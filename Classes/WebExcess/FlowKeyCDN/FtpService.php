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

use \Touki\FTP\Connection\Connection;
use \Touki\FTP\FTP;
use \Touki\FTP\FTPWrapper;
use \Touki\FTP\PermissionsFactory;
use \Touki\FTP\FilesystemFactory;
use \Touki\FTP\WindowsFilesystemFactory;
use \Touki\FTP\DownloaderVoter;
use \Touki\FTP\UploaderVoter;
use \Touki\FTP\CreatorVoter;
use \Touki\FTP\DeleterVoter;
use \Touki\FTP\Manager\FTPFilesystemManager;
use \Touki\FTP\Model\Directory;
use \Touki\FTP\Model\File;

class FtpService {

    /**
     * @var FTP
     */
    protected $ftp;

    /**
     * Hostname of KeyCDN FTP
     *
     * @var string
     */
    protected $host;

    /**
     * Username of KeyCDN FTP
     *
     * @var string
     */
    protected $user;

    /**
     * Password of KeyCDN FTP
     *
     * @var string
     */
    protected $pass;

    /**
     * Zone name of KeyCDN
     *
     * @var string
     */
    protected $zone;

    /**
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param string $zone
     */
    public function setSettings ($host, $user, $pass, $zone) {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->zone = $zone;
    }

    /**
     * @throws \Touki\FTP\Exception\ConnectionEstablishedException
     * @throws \Touki\FTP\Exception\ConnectionException
     */
    private function initializeFtp () {

        $connection = new Connection($this->host, $this->user, $this->pass, 21);
        $connection->open();

        /**
         * The wrapper is a simple class which wraps the base PHP ftp_* functions
         * It needs a Connection instance to get the related stream
         */
        $wrapper = new FTPWrapper($connection);
        $wrapper->pasv(TRUE);

        /**
         * This factory creates Permissions models from a given permission string (rw-)
         */
        $permFactory = new PermissionsFactory;

        /**
         * This factory creates Filesystem models from a given string, ex:
         *     drwxr-x---   3 vincent  vincent      4096 Jul 12 12:16 public_ftp
         *
         * It needs the PermissionsFactory so as to instanciate the given permissions in
         * its model
         */
        $fsFactory = new FilesystemFactory($permFactory);

        /**
         * If your server runs on WINDOWS, you can use a Windows filesystem factory instead
         */
        // $fsFactory = new WindowsFilesystemFactory;

        /**
         * This manager focuses on operations on remote files and directories
         * It needs the FTPWrapper so as to do operations on the serveri
         * It needs the FilesystemFfactory so as to create models
         */
        $manager = new FTPFilesystemManager($wrapper, $fsFactory);


        /**
         * This is the downloader voter. It loads multiple DownloaderVotable class and
         * checks which one is needed on given options
         */
        $dlVoter = new DownloaderVoter;

        /**
         * Loads up default FTP Downloaders
         * It needs the FTPWrapper to be able to share them with the downloaders
         */
        $dlVoter->addDefaultFTPDownloaders($wrapper);

        /**
         * This is the uploader voter. It loads multiple UploaderVotable class and
         * checks which one is needed on given options
         */
        $ulVoter = new UploaderVoter;

        /**
         * Loads up default FTP Uploaders
         * It needs the FTPWrapper to be able to share them with the uploaders
         */
        $ulVoter->addDefaultFTPUploaders($wrapper);

        /**
         * This is the creator voter. It loads multiple CreatorVotable class and
         * checks which one is needed on the given options
         */
        $crVoter = new CreatorVoter;

        /**
         * Loads up the default FTP creators.
         * It needs the FTPWrapper and the FTPFilesystemManager to be able to share
         * them whith the creators
         */
        $crVoter->addDefaultFTPCreators($wrapper, $manager);

        /**
         * This is the deleter voter. It loads multiple DeleterVotable classes and
         * checks which one is needed on the given options
         */
        $deVoter = new DeleterVoter;

        /**
         * Loads up the default FTP deleters.
         * It needs the FTPWrapper and the FTPFilesystemManager to be able to share
         * them with the deleters
         */
        $deVoter->addDefaultFTPDeleters($wrapper, $manager);

        /**
         * Finally creates the main FTP
         * It needs the manager to do operations on files
         * It needs the download voter to pick-up the right downloader on ->download
         * It needs the upload voter to pick-up the right uploader on ->upload
         * It needs the creator voter to pick-up the right creator on ->create
         * It needs the deleter voter to pick-up the right deleter on ->delete
         */
        $this->ftp = new FTP($manager, $dlVoter, $ulVoter, $crVoter, $deVoter);

    }

    /**
     * @param string $localPathAndFilename
     * @param string $remotePathAndFilename
     */
    public function upload ($localPathAndFilename, $remotePathAndFilename) {
        if ( !$this->ftp ) {
            $this->initializeFtp();
        }
        $remotePathAndFilename = '/'.$this->zone.'/'.$remotePathAndFilename;
        $remotePath = dirname($remotePathAndFilename);

        $dir = new Directory($remotePath);
        //if ( !$this->ftp->directoryExists($dir) ) {
            $options = array(
                FTP::RECURSIVE => true
            );
            $this->ftp->create($dir, $options);
        //}

        $file = new File($remotePathAndFilename);
        //if ( !$this->ftp->fileExists($file) ) {
            $this->ftp->upload($file, $localPathAndFilename);
        //}
    }

    /**
     * @param $localPathAndFilename
     * @param $remotePathAndFilename
     * @throws \Touki\FTP\Exception\DirectoryException
     */
    public function download ($localPathAndFilename, $remotePathAndFilename) {
        if ( !$this->ftp ) {
            $this->initializeFtp();
        }
        $remotePathAndFilename = '/'.$this->zone.'/'.$remotePathAndFilename;

        $file = $this->ftp->findFileByName($remotePathAndFilename);
        $this->ftp->download($localPathAndFilename, $file);
    }

    /**
     * @param $remotePathAndFilename
     */
    public function delete ($remotePathAndFilename) {
        if ( !$this->ftp ) {
            $this->initializeFtp();
        }
        $remotePathAndFilename = '/'.$this->zone.'/'.$remotePathAndFilename;
        $remotePath = dirname($remotePathAndFilename);

        $dir = $this->ftp->findDirectoryByName($remotePath);
        if ( substr_count($remotePath, '/')>=2 && $dir && $this->ftp->directoryExists($dir) ) {
            $this->ftp->delete($dir);
        }

        $file = $this->ftp->findFileByName($remotePathAndFilename);
        if ( $this->ftp->fileExists($file) ) {
            $this->ftp->delete($file);
        }
    }

    /**
     * @param string $remotePathAndFilename
     * @return bool
     */
    public function fileExists ($remotePathAndFilename) {
        if ( !$this->ftp ) {
            $this->initializeFtp();
        }
        $remotePathAndFilename = '/'.$this->zone.'/'.$remotePathAndFilename;

        $file = $this->ftp->findFileByName($remotePathAndFilename);
        if ( $file && $this->ftp->fileExists($file) ) {
            return TRUE;
        }
        return FALSE;
    }

} 

