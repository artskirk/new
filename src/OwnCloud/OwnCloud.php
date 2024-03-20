<?php

namespace Datto\OwnCloud;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;
use Psr\Log\LoggerAwareInterface;
use Throwable;
use Datto\Util\ContainerManager;

/**
 * Top-level control class for ownCloud/DattoDrive operations. Delegates a number of tasks
 * to single-responsibility helper classes, but is the primary entry-point for management
 * of the containerized ownCloud installation.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class OwnCloud implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const LOCAL_CONFIG_ADMIN_PASS = 'ownCloudAdminPassword';
    private const LOCAL_CONFIG_PASSWORD_SALT = 'ownCloudPasswordSalt';
    private const LOCAL_CONFIG_INSTANCE_ID = 'ownCloudInstanceId';
    private const LOCAL_CONFIG_SECRET = 'ownCloudSecret';

    private const CONFIG_INSTALLED = 'ownCloudInstalled';
    private const CONFIG_ENABLED = 'ownCloudEnabled';

    private const INSTALL_LOCK = "/var/run/datto/owncloud.lock";
    private const INSTALL_LOCK_WAIT_TIME = 180;

    private const CONTAINER_NAME = 'dattodrive';

    private Filesystem $filesystem;
    private ProcessFactory $processFactory;
    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private LockFactory $lockFactory;
    private ContainerManager $containerManager;
    private OwnCloudStorage $ownCloudStorage;
    private OwnCloudExternal $ownCloudExternal;
    private OwnCloudUser $ownCloudUser;

    public function __construct(
        Filesystem $filesystem,
        ProcessFactory $processFactory,
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        LockFactory $lockFactory,
        ContainerManager $containerManager,
        OwnCloudStorage $ownCloudStorage,
        OwnCloudExternal $ownCloudExternal,
        OwnCloudUser $ownCloudUser
    ) {
        $this->filesystem = $filesystem;
        $this->processFactory = $processFactory;
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->lockFactory = $lockFactory;
        $this->containerManager = $containerManager;
        $this->ownCloudStorage = $ownCloudStorage;
        $this->ownCloudExternal = $ownCloudExternal;
        $this->ownCloudUser = $ownCloudUser;

        $this->containerManager->setContainer(self::CONTAINER_NAME);
    }

    /**
     * Starts the ownCloud uninstaller if ownCloud is installed, and uninstalls ownCloud, but
     * leaves the user data in-tact.
     */
    public function uninstall(): bool
    {
        $success = false;

        // Grab the install lock
        $lock = $this->lockFactory->create(self::INSTALL_LOCK);
        if (!$lock->exclusiveAllowWait(self::INSTALL_LOCK_WAIT_TIME)) {
            return false;
        }

        if (!$this->isInstalled()) {
            $this->logger->warning('OCL0009 DattoDrive Local is not installed');
        }

        $this->logger->info('OCL0010 Uninstalling DattoDrive Local');

        try {
            // Disable the service and stop the running container
            $this->disable();

            // Remove the container
            $this->containerManager->remove();

            // Clear the `installed` config key
            $this->logger->info('OCL0011 DattoDrive Local Successfully Uninstalled');
            $this->deviceConfig->clear(self::CONFIG_INSTALLED);

            $success = true;
        } catch (Throwable $throwable) {
            $this->logger->error('OCL0012 Could not uninstall DattoDrive Local', [
                'error' => $throwable->getMessage()
            ]);
        }
        return $success;
    }

    /**
     * If ownCloud is installed this will uninstall it and purge all associated files and directories
     * including the the data directory where users uploaded files are stored.
     * The script removes the 'datto-owncloud-enteprise' package.
     */
    public function purge(): void
    {
        if ($this->uninstall()) {
            $this->ownCloudStorage->destroyStorage();
            $this->purgeConfigKeys();
        }
    }

    /**
     * Returns whether or not ownCloud is installed. ownCloud is installed if the
     * ownCloudInstalled config flag is set.
     *
     * @return bool True if ownCloud is installed, false otherwise
     */
    public function isInstalled(): bool
    {
        return $this->deviceConfig->has(self::CONFIG_INSTALLED);
    }

    /**
     * Returns whether or not ownCloud is enabled globally. ownCloud is
     * enabled if the symlink at /datto/web/owncloud or /datto/web/dattodrive
     * is present.
     *
     * @return bool True if ownCloud is enabled globally, false otherwise
     */
    public function isEnabled(): bool
    {
        return $this->deviceConfig->has(self::CONFIG_ENABLED);
    }

    /**
     * Disable owncloud and stop the external forwarding service
     */
    public function disable(): void
    {
        $this->deviceConfig->clear(self::CONFIG_ENABLED);

        // Disable the external rly forward
        $this->ownCloudExternal->disable();

        // Disable and stop the container
        $this->containerManager->disable();
        $this->containerManager->stop();
    }

    /**
     * Removes the ownCloud configuration keys generated during install
     */
    private function purgeConfigKeys(): void
    {
        $this->localConfig->clear(self::LOCAL_CONFIG_ADMIN_PASS);
        $this->localConfig->clear(self::LOCAL_CONFIG_INSTANCE_ID);
        $this->localConfig->clear(self::LOCAL_CONFIG_PASSWORD_SALT);
        $this->localConfig->clear(self::LOCAL_CONFIG_SECRET);
    }
}
