<?php

namespace Datto\Asset\Agent\Windows\Serializer;

use Datto\Asset\Serializer\Serializer;
use Datto\Asset\Agent\Windows\BackupSettings;

/**
 * Serialize and unserialize a BackupSettings object into the legacy
 * key files '.backupEngine'
 *
 * Unserializing:
 *   $backupSettings = $serializer->unserialize(array(
 *       'backupEngine' => 'both'
 *   ));
 *
 * Serializing:
 *   $serializedBackupSettings = $serializer->serialize(new BackupSettings());
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class BackupSettingsSerializer implements Serializer
{
    /**
     * Serializes a BackupSettings object into an array of string representing the matching config files
     * .backupEngine'
     *
     * @param BackupSettings $backup
     * @return array
     */
    public function serialize($backup)
    {
        return ['backupEngine' => $backup->getBackupEngine()];
    }

    /**
     * Unserializes an array of the file contents of '.interval', '.backupPause',
     * '.schedule', and '.retention' into a LocalSettings object.
     *
     * @param array $fileArray Array of strings containing the file contents of above listed files.
     * @return BackupSettings
     */
    public function unserialize($fileArray)
    {
        return new BackupSettings($fileArray['backupEngine'] ?? null);
    }
}
