<?php

namespace Datto\System;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * This class uses 'mount --bind' to bind an asset directory
 * to a user directory. It may be used to create a virtual user
 * file system inside a user root.
 *
 * Example:
 *    SftpManager uses /var/sftp as a root directory. A user 'user1'
 *    might mount bind an asset root folder at /var/sftp/user1/asset1.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
abstract class AbstractMountBindManager
{
    const SFTP_ACCESSIBLE_PERMISSIONS = 0755;// Required for SFTP connections to a private share
    const SAMBA_ACCESSIBLE_PERMISSIONS = 0777;// Required for samba connections to a private share from windows
    const MKDIR_MODE = 0777;

    /** @var LoggerFactory */
    protected $loggerFactory;

    /** @var Filesystem */
    protected $filesystem;

    /** @var ProcessFactory */
    protected $processFactory;

    /** @var MountManager */
    protected $mountManager;

    /** @var string */
    protected $rootDir;

    public function __construct(
        string $rootDir,
        LoggerFactory $loggerFactory = null,
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null,
        MountManager $mountManager = null
    ) {
        $this->rootDir = $rootDir;
        $this->loggerFactory = $loggerFactory ?: new LoggerFactory();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
        $this->mountManager = $mountManager ?: new MountManager();
    }

    /**
     * Mounts a new directory for the specified point for the target user. This essentially will give the user access
     * to the target directory via SFTP.
     *
     * @param string $username
     * @param string $asset
     * @param string $source
     */
    public function mount(string $username, string $asset, string $source)
    {
        $logger = $this->loggerFactory->getAsset($asset);

        $userDir = $this->getUserDirectory($username);
        $mountPoint = $userDir . '/' . $asset;

        @$this->filesystem->mkdir($userDir, false, self::MKDIR_MODE);
        @$this->filesystem->mkdir($mountPoint, false, self::MKDIR_MODE);

        $logger->info("BND0001 Mount-binding source to mountPoint", ['source' => $source, 'mountPoint' => $mountPoint]);

        $process = $this->processFactory->get(['mount', '--rbind', $source, $mountPoint]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new Exception('Could not mount directory:' . $process->getOutput() . "\n" . $process->getErrorOutput());
        }

        @$this->filesystem->chmod($userDir, self::SFTP_ACCESSIBLE_PERMISSIONS);
        @$this->filesystem->chmod($mountPoint, self::SAMBA_ACCESSIBLE_PERMISSIONS);
    }

    /**
     * Checks if a user has a specified point already mounted
     *
     * @param string $username
     * @param string $asset
     * @return bool
     */
    public function mountExists(string $username, string $asset)
    {
        $mountPoint = $this->getUserDirectory($username) . '/' . $asset;
        $mounts = $this->mountManager->getMounts();

        foreach ($mounts as $mount) {
            if ($mount->getMountPoint() === $mountPoint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unmounts the specified point for the target user.
     *
     * @param string $username
     * @param string $asset
     */
    public function unmount(string $username, string $asset)
    {
        $logger = $this->loggerFactory->getAsset($asset);

        $userDir = $this->getUserDirectory($username);
        $mountPoint = $userDir . '/' . $asset;

        if ($this->mountExists($username, $asset)) {
            $logger->info("BND0002 Unmounting mountPoint", ['mountPoint' => $mountPoint]);

            $process = $this->processFactory->get(['umount', '-f', '-R', $mountPoint]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new Exception('Could not unmount directory:' . $process->getOutput() . "\n" . $process->getErrorOutput());
            }
        }

        $logger->info("BND0003 Removing empty mountpoint", ['mountPoint' => $mountPoint]);

        @$this->filesystem->rmdir($mountPoint);
        @$this->filesystem->rmdir($userDir);
    }

    /**
     * @param string $username
     * @return string
     */
    protected function getUserDirectory(string $username)
    {
        return $this->rootDir . '/' . $username;
    }
}
