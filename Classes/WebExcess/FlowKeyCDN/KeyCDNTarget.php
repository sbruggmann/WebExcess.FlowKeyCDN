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
use TYPO3\Flow\Resource\Collection;
use TYPO3\Flow\Resource\CollectionInterface;
use TYPO3\Flow\Resource\Exception;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Flow\Resource\ResourceMetaDataInterface;
use TYPO3\Flow\Resource\Storage\Object;
use TYPO3\Flow\Resource\Target\TargetInterface;
use TYPO3\Flow\Utility\Environment;

/**
 * A resource publishing target based on KeyCDN
 */
class KeyCDNTarget implements TargetInterface {

    /**
     * Name which identifies this resource target
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
     * If Content Delivery Network should be enabled this array contains the respective
     * base URIs
     *
     * @var array
     */
    protected $cdn = array();

    /**
     * Name of the CloudFiles container which should be used for publication
     *
     * @var string
     */
    protected $containerName;

    /**
     * CORS (Cross-Origin Resource Sharing) allowed origins for published content
     *
     * @var string
     */
    protected $corsAllowOrigin = '*';

    /**
     * Internal cache for known storages, indexed by storage name
     *
     * @var array<\TYPO3\Flow\Resource\Storage\StorageInterface>
     */
    protected $storages = array();

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @var array
     */
    protected $existingObjectsInfo;

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
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * Constructor
     *
     * @param string $name Name of this target instance, according to the resource settings
     * @param array $options Options for this target
     * @throws Exception
     */
    public function __construct($name, array $options = array()) {
        $this->name = $name;
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
            $this->systemLogger->log('target ' . $this->name . ': uploadFile $localPath: ' . $localPath . ', $remoteName: ' . $remoteName);
        }
        $this->ftpService->upload($localPath, $remoteName);
    }

    /**
     * @param string $localPath
     * @param string $remoteName
     */
    private function downloadFile ($localPath, $remoteName) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': downloadFile');
        }
        $this->ftpService->download($localPath, $remoteName);
    }

    /**
     * @param string $remoteName
     */
    private function deleteFile ($remoteName) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': deleteFile $remoteName: ' . $remoteName);
        }
        $this->ftpService->delete($remoteName);
    }

    /**
     * Returns the name of this target instance
     *
     * @return string The target instance name
     */
    public function getName() {
        return $this->name;
    }

    /**
     * Publishes the whole collection to this target
     *
     * @param \TYPO3\Flow\Resource\Collection $collection The collection to publish
     * @return void
     * @throws Exception
     */
    public function publishCollection(Collection $collection) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': publishCollection');
        }
        if (!isset($this->existingObjectsInfo)) {
            $this->existingObjectsInfo = array();
        }
        $obsoleteObjects = array_fill_keys(array_keys($this->existingObjectsInfo), TRUE);

        $storage = $collection->getStorage();
        if ($storage instanceof KeyCDNStorage) {
            if ( $this->debug ) {
                $this->systemLogger->log('target ' . $this->name . ': - publishCollection instanceof: KeyCDNStorage');
            }
            $storageContainerName = $storage->getContainerName();
            if ($storageContainerName === $this->containerName) {
                throw new Exception(sprintf('Could not publish collection %s because the source and target container is the same.', $collection->getName()), 1375348241);
            }
            foreach ($collection->getObjects() as $object) {
                /** @var \TYPO3\Flow\Resource\Storage\Object $object */
                $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('TYPO3_Flow_ResourceImport_');
                $this->downloadFile($temporaryTargetPathAndFilename, '_'.$object->getSha1());
                $this->uploadFile($temporaryTargetPathAndFilename, $this->getRelativePublicationPathAndFilename($object));

                unset($obsoleteObjects[$this->getRelativePublicationPathAndFilename($object)]);
            }
        } else {
            if ( $this->debug ) {
                $this->systemLogger->log('target ' . $this->name . ': - publishCollection');
            }
            foreach ($collection->getObjects() as $object) {
                /** @var \TYPO3\Flow\Resource\Storage\Object $object */
                $this->publishFile($object->getStream(), $this->getRelativePublicationPathAndFilename($object), $object);
                unset($obsoleteObjects[$this->getRelativePublicationPathAndFilename($object)]);
            }
        }

        foreach (array_keys($obsoleteObjects) as $relativePathAndFilename) {
            $this->deleteFile($relativePathAndFilename);
        }
    }

    /**
     * Returns the web accessible URI pointing to the given static resource
     *
     * @param string $relativePathAndFilename Relative path and filename of the static resource
     * @return string The URI
     */
    public function getPublicStaticResourceUri($relativePathAndFilename) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': getPublicStaticResourceUri return: http://'.$this->zoneDomain.'/'.$relativePathAndFilename);
        }
        return 'http://'.$this->zoneDomain.'/'.$relativePathAndFilename;
    }

    /**
     * Publishes the given persistent resource from the given storage
     *
     * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
     * @param CollectionInterface $collection The collection the given resource belongs to
     * @return void
     * @throws Exception
     */
    public function publishResource(Resource $resource, CollectionInterface $collection) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': publishResource');
        }
        $storage = $collection->getStorage();
        if ($storage instanceof KeyCDNStorage) {
            if ($storage->getContainerName() === $this->containerName) {
                throw new Exception(sprintf('Could not publish resource with SHA1 hash %s of collection %s because the source and target container is the same.', $resource->getSha1(), $collection->getName()), 1375348223);
            }
            $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('TYPO3_Flow_ResourceImport_');
            $this->downloadFile($temporaryTargetPathAndFilename, '_'.$resource->getSha1());
            $this->uploadFile($temporaryTargetPathAndFilename, $this->getRelativePublicationPathAndFilename($resource));
        } else {
            $sourceStream = $collection->getStreamByResource($resource);
            if ($sourceStream === FALSE) {
                throw new Exception(sprintf('Could not publish resource with SHA1 hash %s of collection %s because there seems to be no corresponding data in the storage.', $resource->getSha1(), $collection->getName()), 1375342304);
            }
            $this->publishFile($sourceStream, $this->getRelativePublicationPathAndFilename($resource), $resource);
        }
    }

    /**
     * Unpublishes the given persistent resource
     *
     * @param \TYPO3\Flow\Resource\Resource $resource The resource to unpublish
     * @return void
     */
    public function unpublishResource(Resource $resource) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': unpublishResource');
        }
        try {
            $this->deleteFile($this->getRelativePublicationPathAndFilename($resource));
        } catch (\Exception $e) {
        }
    }

    /**
     * Returns the web accessible URI pointing to the specified persistent resource
     *
     * @param \TYPO3\Flow\Resource\Resource $resource Resource object or the resource hash of the resource
     * @return string The URI
     * @throws Exception
     */
    public function getPublicPersistentResourceUri(Resource $resource) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': getPublicPersistentResourceUri return: http://'.$this->zoneDomain.'/'.$this->getRelativePublicationPathAndFilename($resource));
        }
        return 'http://'.$this->zoneDomain.'/'.$this->getRelativePublicationPathAndFilename($resource);
    }

    /**
     * Publishes the specified source file to this target, with the given relative path.
     *
     * @param resource $sourceStream
     * @param string $relativeTargetPathAndFilename
     * @param ResourceMetaDataInterface $metaData
     * @throws Exception
     * @return void
     */
    protected function publishFile($sourceStream, $relativeTargetPathAndFilename, ResourceMetaDataInterface $metaData) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': publishFile $relativeTargetPathAndFilename: ' . $relativeTargetPathAndFilename);
        }
        if ( !isset($this->existingObjectsInfo) ) {
            if ( $this->debug ) {
                $this->systemLogger->log('target ' . $this->name . ': - publishFile 1');
            }
        }

        $temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('TYPO3_Flow_ResourceImport_');
        file_put_contents($temporaryTargetPathAndFilename, $sourceStream);
        $this->uploadFile($temporaryTargetPathAndFilename, $relativeTargetPathAndFilename);

        fclose($sourceStream);
    }

    /**
     * Determines and returns the relative path and filename for the given Storage Object or Resource. If the given
     * object represents a persistent resource, its own relative publication path will be empty. If the given object
     * represents a static resources, it will contain a relative path.
     *
     * @param ResourceMetaDataInterface $object Resource or Storage Object
     * @return string The relative path and filename, for example "c828d0f88ce197be1aff7cc2e5e86b1244241ac6/MyPicture.jpg"
     */
    protected function getRelativePublicationPathAndFilename(ResourceMetaDataInterface $object) {
        if ( $this->debug ) {
            $this->systemLogger->log('target ' . $this->name . ': getRelativePublicationPathAndFilename');
        }
        if ($object->getRelativePublicationPath() !== '') {
            $pathAndFilename = $object->getRelativePublicationPath() . $object->getFilename();
        } else {
            $pathAndFilename = $object->getSha1() . '/' . $object->getFilename();
        }
        return $pathAndFilename;
    }

}

?>