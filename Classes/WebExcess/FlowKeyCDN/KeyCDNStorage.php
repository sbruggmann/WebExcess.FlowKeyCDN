<?php
namespace WebExcess\FlowKeyCDN;

/**
 * A Package to use KeyCDN for persistent resources in the Flow Framework
 *
 * @link        https://www.keycdn.com/
 *
 * @category    Flow Framework
 * @package     FlowKeyCDN
 * @link        https://github.com/sbruggmann/WebExcess.FlowKeyCDN
 * @author      <stefan.bruggmann@web-excess.ch>
 *
 * @see         Reference implementation of Robert Lemke
 * @link        https://github.com/robertlemke/RobertLemke.RackspaceCloudFiles
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\CollectionInterface;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Flow\Resource\ResourceRepository;
use TYPO3\Flow\Resource\Storage\Exception as StorageException;
use TYPO3\Flow\Resource\Storage\Exception;
use TYPO3\Flow\Resource\Storage\Object;
use TYPO3\Flow\Resource\Storage\WritableStorageInterface;
use TYPO3\Flow\Utility\Environment;

/**
 * A resource storage based on KeyCDN
 */
class KeyCDNStorage implements WritableStorageInterface {

    /**
     * Name which identifies this resource storage
     *
     * @var string
     */
    protected $name;

    /**
     * Hostname of KeyCDN FTP
     *
     * @Flow\InjectConfiguration(path="default.host")
     * @var string
     */
    protected $host;

    /**
     * Username of KeyCDN FTP
     *
     * @Flow\InjectConfiguration(path="default.user")
     * @var string
     */
    protected $user;

    /**
     * Password of KeyCDN FTP
     *
     * @Flow\InjectConfiguration(path="default.pass")
     * @var string
     */
    protected $pass;

    /**
     * Zone name of KeyCDN
     *
     * @Flow\InjectConfiguration(path="default.zone")
     * @var string
     */
    protected $zone;

    /**
     * Zone domain of KeyCDN
     *
     * @Flow\InjectConfiguration(path="default.zoneDomain")
     * @var string
     */
    protected $zoneDomain;

    /**
     * Key of KeyCDN API
     *
     * @Flow\InjectConfiguration(path="default.apiKey")
     * @var string
     */
    protected $apiKey;

    /**
     * Log debug messages
     *
     * @Flow\InjectConfiguration(path="default.debug")
     * @var boolean
     */
    protected $debug;

    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ResourceRepository
     */
    protected $resourceRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Log\SystemLoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var FtpService
     */
    protected $ftpService;

    /**
     * Constructor
     *
     * @param string $name Name of this storage instance, according to the resource settings
     * @param array $options Options for this storage
     * @throws Exception
     */
    public function __construct($name, array $options = array()) {
        $this->name = $name;
        $this->containerName = $name;
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'host':
                    $this->host = $value;
                    break;
                case 'user':
                    $this->user = $value;
                    break;
                case 'pass':
                    $this->pass = $value;
                    break;
                case 'zone':
                    $this->zone = $value;
                    break;
                case 'zoneDomain':
                    $this->zoneDomain = $value;
                    break;
                case 'apiKey':
                    $this->apiKey = $value;
                    break;
            }
        }
    }

    /**
     * Initialize this service
     *
     * @return void
     */
    public function initializeObject() {
        $this->ftpService->setSettings($this->host, $this->user, $this->pass, $this->zone);
    }

    /**
     * @param string $localPath
     * @param string $remoteName
     */
    private function uploadFile ($localPath, $remoteName) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': uploadFile $localPath: ' . $localPath . ', $remoteName: ' . $remoteName);
        }
        $this->ftpService->upload($localPath, $remoteName);
    }

    /**
     * @param string $localPath
     * @param string $remoteName
     */
    private function downloadFile ($localPath, $remoteName) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': downloadFile $localPath: '.$localPath.', $remoteName: '.$remoteName);
        }
        $this->ftpService->download($localPath, $remoteName);
    }

    /**
     * @param string $remoteName
     */
    private function deleteFile ($remoteName) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': deleteFile $remoteName: ' . $remoteName);
        }
        $this->ftpService->delete($remoteName);
    }

    /**
     * Returns the instance name of this storage
     *
     * @return string
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Returns the Rackspace Cloudfiles container name used as a storage
     *
     * @return string
     */
    public function getContainerName() {
        return $this->containerName;
    }

    /**
     * Imports a resource (file) from the given URI or PHP resource stream into this storage.
     *
     * On a successful import this method returns a Resource object representing the newly
     * imported persistent resource.
     *
     * @param string | resource $source The URI (or local path and filename) or the PHP resource stream to import the resource from
     * @param string $collectionName Name of the collection the new Resource belongs to
     * @return Resource A resource object representing the imported resource
     * @throws \TYPO3\Flow\Resource\Storage\Exception
     * TODO: Don't upload file again if it already exists
     */
    public function importResource($source, $collectionName) {
        if ($this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': importResource');
        }
        if (is_resource($source)) {
            $sourceContent = stream_get_contents($source);
            return $this->importResourceFromContent($sourceContent, $collectionName);
        }

        $pathInfo = pathinfo($source);
        $originalFilename = $pathInfo['basename'];
        $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('Sbruggmann_FlowKeyCDN_');

        if ( copy($source, $temporaryTargetPathAndFilename) === FALSE ) {
            throw new StorageException(sprintf('Could not copy the file from "%s" to temporary file "%s".', $source, $temporaryTargetPathAndFilename), 1375266771);
        }

        $sha1Hash = sha1_file($temporaryTargetPathAndFilename);
        $md5Hash = md5_file($temporaryTargetPathAndFilename);

        $resource = new Resource();
        $resource->setFilename($originalFilename);
        $resource->setFileSize(filesize($temporaryTargetPathAndFilename));
        $resource->setCollectionName($collectionName);
        $resource->setSha1($sha1Hash);
        $resource->setMd5($md5Hash);

        $this->uploadFile($temporaryTargetPathAndFilename, '_'.$sha1Hash);

        return $resource;
    }

    /**
     * Imports a resource from the given string content into this storage.
     *
     * On a successful import this method returns a Resource object representing the newly
     * imported persistent resource.
     *
     * The specified filename will be used when presenting the resource to a user. Its file extension is
     * important because the resource management will derive the IANA Media Type from it.
     *
     * @param string $content The actual content to import
     * @return Resource A resource object representing the imported resource
     * @param string $collectionName Name of the collection the new Resource belongs to
     * @return Resource A resource object representing the imported resource
     * @throws Exception
     * @api
     */
    public function importResourceFromContent($content, $collectionName) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': importResourceFromContent');
        }
        $sha1Hash = sha1($content);
        $md5Hash = md5($content);

        $filename = $sha1Hash; // FIXME

        $resource = new Resource();
        $resource->setFilename($filename);
        $resource->setFileSize(strlen($content));
        $resource->setCollectionName($collectionName);
        $resource->setSha1($sha1Hash);
        $resource->setMd5($md5Hash);

        $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('TYPO3_Flow_ResourceImport_');
        file_put_contents($temporaryTargetPathAndFilename, $content);
        $this->uploadFile($temporaryTargetPathAndFilename, '_'.$filename);

        return $resource;
    }

    /**
     * Imports a resource (file) as specified in the given upload info array as a
     * persistent resource.
     *
     * On a successful import this method returns a Resource object representing
     * the newly imported persistent resource.
     *
     * @param array $uploadInfo An array detailing the resource to import (expected keys: name, tmp_name)
     * @param string $collectionName Name of the collection this uploaded resource should be part of
     * @return string A resource object representing the imported resource
     * @throws Exception
     * @api
     */
    public function importUploadedResource(array $uploadInfo, $collectionName) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': importUploadedResource');
        }
        $pathInfo = pathinfo($uploadInfo['name']);
        $originalFilename = $pathInfo['basename'];
        $sourcePathAndFilename = $uploadInfo['tmp_name'];

        if (!file_exists($sourcePathAndFilename)) {
            throw new Exception(sprintf('The temporary file "%s" of the file upload does not exist (anymore).', $sourcePathAndFilename), 1375267007);
        }

        $newSourcePathAndFilename = $this->environment->getPathToTemporaryDirectory() . 'Sbruggmann_FlowKeyCDN_' . uniqid() . '.tmp';
        if (move_uploaded_file($sourcePathAndFilename, $newSourcePathAndFilename) === FALSE) {
            throw new Exception(sprintf('The uploaded file "%s" could not be moved to the temporary location "%s".', $sourcePathAndFilename, $newSourcePathAndFilename), 1375267045);
        }
        $sha1Hash = sha1_file($newSourcePathAndFilename);
        $md5Hash = md5_file($newSourcePathAndFilename);

        $resource = new Resource();
        $resource->setFilename($originalFilename);
        $resource->setCollectionName($collectionName);
        $resource->setFileSize(filesize($newSourcePathAndFilename));
        $resource->setSha1($sha1Hash);
        $resource->setMd5($md5Hash);

        $this->uploadFile($newSourcePathAndFilename, $originalFilename);

        return $resource;
    }

    /**
     * Deletes the storage data related to the given Resource object
     *
     * @param \TYPO3\Flow\Resource\Resource $resource The Resource to delete the storage data of
     * @return boolean TRUE if removal was successful
     * @api
     */
    public function deleteResource(Resource $resource) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': deleteResource');
        }
        $this->deleteFile('_'.$resource->getSha1());
        return TRUE;
    }

    /**
     * Returns a stream handle which can be used internally to open / copy the given resource
     * stored in this storage.
     *
     * @param \TYPO3\Flow\Resource\Resource $resource The resource stored in this storage
     * @return resource | boolean A URI (for example the full path and filename) leading to the resource file or FALSE if it does not exist
     * @api
     */
    public function getStreamByResource(Resource $resource) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': getStreamByResource');
        }
        if ( $this->ftpService->fileExists('_' . $resource->getSha1()) ) {
            if ( $this->debug ) {
                $this->systemLogger->log('storage ' . $this->name . ': - getStreamByResource ' . 'http://'.$this->zoneDomain.'/_' . $resource->getSha1());
            }
            return fopen('http://'.$this->zoneDomain.'/_' . $resource->getSha1(), 'r');
        }else{
            if ( $this->debug ) {
                $this->systemLogger->log('storage ' . $this->name . ': - getStreamByResource file _' . $resource->getSha1().' not exists');
            }
            return FALSE;
        }
    }

    /**
     * Returns a stream handle which can be used internally to open / copy the given resource
     * stored in this storage.
     *
     * @param string $relativePath A path relative to the storage root, for example "MyFirstDirectory/SecondDirectory/Foo.css"
     * @return resource | boolean A URI (for example the full path and filename) leading to the resource file or FALSE if it does not exist
     * @api
     */
    public function getStreamByResourcePath($relativePath) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': getStreamByResourcePath');
        }
        if ( $this->ftpService->fileExists('_' . ltrim('/', $relativePath)) ) {
            return fopen('http://'.$this->zoneDomain.'/_' . ltrim('/', $relativePath), 'r');
        }else{
            return FALSE;
        }
    }

    /**
     * Retrieve all Objects stored in this storage.
     *
     * @return array<\TYPO3\Flow\Resource\Storage\Object>
     * @api
     */
    public function getObjects() {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': getObjects');
        }
        $objects = array();
        foreach ($this->resourceManager->getCollectionsByStorage($this) as $collection) {
            $objects = array_merge($objects, $this->getObjectsByCollection($collection));
        }
        return $objects;
    }

    /**
     * Retrieve all Objects stored in this storage, filtered by the given collection name
     *
     * @param CollectionInterface $collection
     * @internal param string $collectionName
     * @return array<\TYPO3\Flow\Resource\Storage\Object>
     * @api
     */
    public function getObjectsByCollection(CollectionInterface $collection) {
        if ( $this->debug ) {
            $this->systemLogger->log('storage ' . $this->name . ': getObjectsByCollection, $collection->getName(): ' . $collection->getName());
        }
        $objects = array();
        $that = $this;
        $containerName = $this->containerName;

        foreach ($this->resourceRepository->findByCollectionName($collection->getName()) as $resource) {
            /** @var \TYPO3\Flow\Resource\Resource $resource */
            if ( $this->debug ) {
                $this->systemLogger->log('storage ' . $this->name . ': - getObjectsByCollection $resource->getFilename(): ' . $resource->getFilename());
            }
            $object = new Object();
            $object->setFilename($resource->getFilename());
            $object->setSha1($resource->getSha1());
            $object->setStream(function () use ($that, $containerName, $resource) {
                return 'http://'.$this->zoneDomain.'/_' . $resource->getSha1();
            });
            $objects[] = $object;
        }

        return $objects;
    }

}

?>