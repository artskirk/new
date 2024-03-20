<?php

namespace Datto\Asset\Share\Nas;

use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Samba\SambaManager;
use Datto\Utility\Network\Zeroconf\Avahi;

/**
 * Manages the APFS settings and service for a specific share.
 *
 * Developer note:
 *   Be sure to make all properties injectable through the constructor, so that the
 *   state of the object can be recreated from a config file. Do NOT provide public
 *   setters for properties that could set the object into an inconsistent state,
 *   e.g. don't provide a setEnabled() method.
 *
 * The template used in this file is based on the content provided in this post:
 * https://www.reddit.com/r/homelab/comments/83vkaz/howto_make_time_machine_backups_on_a_samba/
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class ApfsSettings extends AbstractSettings
{
    public const DEFAULT_ENABLED = false;
    private const TIMEMACHINE_GLOBAL_CONFIG = '/datto/config/timeMachineConfig';
    private const GLOBAL_TIMEMACHINE_CONFIG_CONTENTS = <<<EOF
[global]
fruit:model = MacPro
fruit:aapl = yes
EOF;
    const VFS_OBJECTS_PROPERTY_VALUE = 'catia fruit streams_xattr';
    const VFS_OBJECTS_PROPERTY_KEY = 'vfs objects';
    const FRUIT_TIME_MACHINE_PROPERTY_KEY = 'fruit:time machine';
    const FRUIT_TIME_MACHINE_PROPERTY_VALUE = 'yes';

    private bool $enabled;
    private DeviceLoggerInterface $logger;
    private Filesystem $filesystem;
    private Avahi $avahi;

    public function __construct(
        string $name,
        DeviceLoggerInterface $logger,
        SambaManager $samba,
        Filesystem $filesystem,
        Avahi $avahi,
        bool $enabled = self::DEFAULT_ENABLED
    ) {
        parent::__construct($name, $samba);

        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->avahi = $avahi;
        $this->enabled = $enabled;
    }

    /**
     * Returns whether or not APFS time machine support is enabled for this share
     *
     * @return bool True if enabled, false otherwise
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enables APFS support for the share.
     */
    public function enable(): void
    {
        $this->logger->debug('APF0001 Setting APFS to enabled for share');

        $sambaShare = $this->getSambaShare();
        $users = $sambaShare->getAllUsers();
        $hasUsers = count($users) > 0;

        if ($hasUsers) {
            // Add APFS config to samba config of share
             $sambaShare->setProperty(self::VFS_OBJECTS_PROPERTY_KEY, self::VFS_OBJECTS_PROPERTY_VALUE);
             $sambaShare->setProperty(self::FRUIT_TIME_MACHINE_PROPERTY_KEY, self::FRUIT_TIME_MACHINE_PROPERTY_VALUE);
        }

        $this->enabled = true;
        $this->updateSystemConfig();
    }

    /**
     * Disables APFS support for the share, and removes all Samba users from it.
     */
    public function disable(): void
    {
        $this->logger->debug('APF0002 Setting APFS to disabled for share');

        $sambaShare = $this->samba->getShareByName($this->name);

        if ($sambaShare) {
            // Remove APFS config from samba config of share
            $sambaShare->setProperty(self::FRUIT_TIME_MACHINE_PROPERTY_KEY, null);
            $sambaShare->setProperty(self::VFS_OBJECTS_PROPERTY_KEY, null);
        }

        $this->enabled = false;
        $this->updateSystemConfig();
    }

    /**
     * Copies the configuration from another instance of this class,
     * and applies the changes.
     *
     * @param ApfsSettings $from Instance to copy the settings from
     */
    public function copyFrom(ApfsSettings $from): void
    {
        if ($from->isEnabled()) {
            $this->enable();
        } else {
            $this->disable();
        }
    }

    private function updateSystemConfig(): void
    {
        $shares = $this->samba->getAllShares();
        $apfsShares = array_filter($shares, function ($candidate) {
            return ($candidate->getProperty(self::VFS_OBJECTS_PROPERTY_KEY) === self::VFS_OBJECTS_PROPERTY_VALUE) &&
                ($candidate->getProperty(self::FRUIT_TIME_MACHINE_PROPERTY_KEY) === self::FRUIT_TIME_MACHINE_PROPERTY_VALUE);
        });

        $apfsSharesExist = $this->updateAvahiAdvertisedDisks($apfsShares);

        if ($apfsSharesExist) {
            $this->filesystem->filePutContents(self::TIMEMACHINE_GLOBAL_CONFIG, self::GLOBAL_TIMEMACHINE_CONFIG_CONTENTS);
            $this->samba->addInclude(self::TIMEMACHINE_GLOBAL_CONFIG, SambaManager::DEVICE_CONF_FILE);
        } else {
            $this->samba->removeInclude(self::TIMEMACHINE_GLOBAL_CONFIG);
        }
    }

    private function updateAvahiAdvertisedDisks(array $apfsShares): bool
    {
        $existingShares = array_map(function ($apfsShare) {
            return $apfsShare->getName();
        }, $apfsShares);

        $this->avahi->updateAvahiServicesForShares($existingShares, Avahi::AVAHI_SHARE_TYPE_APFS);

        return true;
    }
}
