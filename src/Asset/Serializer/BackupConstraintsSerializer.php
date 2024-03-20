<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\BackupConstraints;

class BackupConstraintsSerializer implements Serializer
{
    const KEY = 'backupConstraints';

    /**
     * @param BackupConstraints|null $backupConstraints
     *
     * @return string|null
     */
    public function serialize($backupConstraints = null)
    {
        if ($backupConstraints === null) {
            return null;
        }

        return json_encode([
            'maxTotalVolumeSize' => $backupConstraints->getMaxTotalVolumeSize(),
            'shouldBackupAllVolumes' => $backupConstraints->shouldBackupAllVolumes()
        ]);
    }

    /**
     * @param string|null $serializedObject
     *
     * @return BackupConstraints
     */
    public function unserialize($serializedObject)
    {
        if ($serializedObject === null) {
            return null;
        }

        $backupConstraints = json_decode($serializedObject, true);
        $maxTotalVolumeSize = $backupConstraints['maxTotalVolumeSize'] ?? null;
        $shouldBackupAllVolumes = $backupConstraints['shouldBackupAllVolumes'] ?? false;

        return new BackupConstraints($maxTotalVolumeSize, $shouldBackupAllVolumes);
    }
}
