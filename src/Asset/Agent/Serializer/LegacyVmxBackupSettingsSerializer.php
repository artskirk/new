<?php

namespace Datto\Asset\Agent\Serializer;

use Datto\Asset\Agent\VmxBackupSettings;
use Datto\Asset\Serializer\Serializer;

/**
 * Class VmxBackupSettingsSerializer: Serializer for Datto\Asset\Agent\VmxBackupSettings
 * @package Datto\Asset\Agent\Serializer
 */
class LegacyVmxBackupSettingsSerializer implements Serializer
{
    const FILE_KEY = 'backupVMX';
    const ENABLED = 'enableVmxBackup';
    const CONNECTION_NAME = 'vmxBackupConnection';

    /**
     * @param VmxBackupSettings $vmxBackupSettings
     * @return array Serialized VmxBackupSettings object
     */
    public function serialize($vmxBackupSettings)
    {
        if (!$vmxBackupSettings->isEnabled()) {
            return [self::FILE_KEY => null];
        }
        return [
            self::FILE_KEY => serialize([
                self::ENABLED => $vmxBackupSettings->isEnabled(),
                self::CONNECTION_NAME => $vmxBackupSettings->getConnectionName(),
            ])
        ];
    }

    /**
     * @param mixed $fileArray Array containing serialized objects
     * @return VmxBackupSettings The VmxBackupSettings as read in from the file array. If no such object is present
     * in the array, the default settings will be returned.
     */
    public function unserialize($fileArray)
    {
        $settings = @unserialize($fileArray[self::FILE_KEY], ['allowed_classes' => false]);
        if (is_array($settings)) {
            $enabled = $settings[self::ENABLED] ?? false;
            $connectionName = $settings[self::CONNECTION_NAME] ?? "";
            $vmxBackupSettings = new VmxBackupSettings($enabled, $connectionName);
        } else {
            $vmxBackupSettings = new VmxBackupSettings();
        }
        return $vmxBackupSettings;
    }
}
