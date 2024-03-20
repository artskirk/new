<?php

namespace Datto\Asset\Agent\Backup\Serializer;

use Datto\Asset\Agent\Backup\DiskDrive;
use Datto\Asset\Serializer\Serializer;

/**
 * Serialize DiskDrive instances.
 *
 * @author Peter Geer <pgeer@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class DiskDriveSerializer implements Serializer
{
    const CURRENT_VERSION = "1.0";

    /**
     * @param DiskDrive[] $disks
     * @return string
     */
    public function serialize($disks): string
    {
        $serializedDisks = [];
        foreach ($disks as $disk) {
            $serializedDisks[] = [
                'uuid' => $disk->getUuid(),
                'path' => $disk->getPath(),
                'capacity' => $disk->getCapacityInBytes(),
                'hasBootablePartition' => $disk->hasBootablePartition(),
                'isGpt' => $disk->isGpt(),
            ];
        }
        return json_encode(['version' => self::CURRENT_VERSION, 'disks' => $serializedDisks]);
    }

    /**
     * Create an object from the given array.
     *
     * @param mixed $serializedObject Serialized object
     * @return DiskDrive[]
     */
    public function unserialize($serializedObject)
    {
        $data = json_decode($serializedObject, true);
        $disks = [];

        if (!$data) {
            return $disks;
        }

        foreach ($data['disks'] as $item) {
            $disks[] = new DiskDrive(
                $item['uuid'],
                $item['path'],
                $item['capacity'],
                $item['hasBootablePartition'],
                $item['isGpt']
            );
        }

        return $disks;
    }

    /**
     * Return a simple array of the disk drives.
     *
     * @param string $serializedObject Serialized object
     * @return array
     */
    public function unserializeAsArray(string $serializedObject): array
    {
        $data = json_decode($serializedObject, true);

        return $data['disks'] ?? [];
    }
}
