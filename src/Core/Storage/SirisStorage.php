<?php

namespace Datto\Core\Storage;

use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * This class contains Siris specific methods, pool, and storage names
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SirisStorage implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const PRIMARY_POOL_NAME = 'homePool';

    // OS2, for the most part, uses the homePool/home dataset as the base point for all other datasets and zvols.
    // This should be reworked after the storage abstraction is in place. Also, we will want to move away from /home as its
    // mountpoint, as this may conflict with linux users' home directories and systemd-homed.
    public const HOME_STORAGE = 'home';

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Get the main home storage id
     *
     * @return string Name of the storage id
     */
    public function getHomeStorageId()
    {
        return $this->getStorageId(self::HOME_STORAGE, StorageType::STORAGE_TYPE_FILE);
    }

    /**
     * Get the storage id based on the storage name and type
     *
     * @param string $storageName Name of the storage
     * @param string $type Storage type
     * @return string Name of the storage id
     */
    public function getStorageId(string $storageName, string $type): string
    {
        if ($storageName === self::HOME_STORAGE) {
            $storageId = self::PRIMARY_POOL_NAME . '/' . self::HOME_STORAGE;
        } elseif ($type === StorageType::STORAGE_TYPE_FILE) {
            $storageId = self::PRIMARY_POOL_NAME . '/' . self::HOME_STORAGE . '/agents/' . $storageName;
        } elseif ($type === StorageType::STORAGE_TYPE_BLOCK) {
            $storageId = self::PRIMARY_POOL_NAME . '/' . self::HOME_STORAGE . '/' . $storageName;
        } else {
            $storageId = '';
        }

        return $storageId;
    }

    /**
     * Get the storage name and parent id from the storage id
     *
     * @param string $storageId Id of the storage to retrieve the name of
     * @return array Name and parent id
     */
    public function getNameAndParentIdFromStorageId(string $storageId): array
    {
        $storageNameParts = explode('/', $storageId);
        $name = array_pop($storageNameParts) ?? '';
        $parentId = implode('/', $storageNameParts);
        return [
            'name' => $name,
            'parentId' => $parentId
        ];
    }

    /**
     * Get the snapshot Id from the storage Id and snapshot name
     *
     * @param string $storageId Id of the storage to derive the snapshot id
     * @param string $snapshotName Name of the snapshot to dervice the snapshot id
     * @return string Id of the snapshot
     */
    public function getSnapshotId(string $storageId, string $snapshotName): string
    {
        return $storageId . '@' . $snapshotName;
    }
}
