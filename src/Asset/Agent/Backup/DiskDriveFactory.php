<?php

namespace Datto\Asset\Agent\Backup;

use Datto\Utility\ByteUnit;

/**
 * Factory class to create DiskDrive objects
 *
 * @author Erik Wilson <erwilson@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class DiskDriveFactory
{
    /**
     * Create a DiskDrive object for each disk in vmdkInfo array
     *
     * @param array $vmdkInfo
     * @return DiskDrive[] indexed by disk UUID
     */
    public function createDiskDrivesFromVmdkInfo(array $vmdkInfo): array
    {
        $diskDrives = [];
        foreach ($vmdkInfo as $disk) {
            $hasBootablePartition = $this->hasBootablePartition($disk);
            $uuid = (string) ($disk['diskUuid'] ?? '');
            $diskPath = (string) ($disk['diskPath'] ?? '');
            $isGpt = (bool) ($disk['isGpt'] ?? false);
            $skipDisk = $uuid === '' || $diskPath === '';

            if (!$skipDisk) {
                $diskDrives[$uuid] = new DiskDrive(
                    $uuid,
                    $diskPath,
                    ByteUnit::KIB()->toByte($disk['diskSizeKiB']),
                    $hasBootablePartition,
                    $isGpt
                );
            }
        }

        return $diskDrives;
    }

    /**
     * Checks if the VMDK image contains partition marked as bootable.
     *
     * @param array $vmdk
     * @return bool
     */
    private function hasBootablePartition(array $vmdk): bool
    {
        foreach ($vmdk['partitions'] as $partition) {
            if (!empty($partition['bootable']) && $partition['bootable']) {
                return true;
            }
        }

        return false;
    }
}
