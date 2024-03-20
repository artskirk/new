<?php

namespace Datto\ZFS;

use Datto\AppKernel;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInfo;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\Zfs\ZfsStorage;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * @author Mario Rial <mrial@datto.com>
 *
 * @deprecated Use StorageInterface instead
 */
class ZfsService
{
    private DeviceLoggerInterface $logger;
    private ZfsCache $cache;
    private Filesystem $filesystem;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        DeviceLoggerInterface $logger = null,
        ZfsCache $cache = null,
        Filesystem $filesystem = null,
        StorageInterface $storage = null,
        SirisStorage $sirisStorage = null
    ) {
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
        $this->cache = $cache ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsCache::class);
        $this->filesystem = $filesystem ?? AppKernel::getBootedInstance()->getContainer()->get(Filesystem::class);
        $this->storage = $storage ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsStorage::class);
        $this->sirisStorage = $sirisStorage ?? AppKernel::getBootedInstance()->getContainer()->get(SirisStorage::class);
    }

    /**
     * Refreshes the cache for all Zfs datasets
     *
     * @param array $datasets
     */
    public function writeCache(array $datasets = []): void
    {
        $existingDatasets = $this->getAllDatasetNames($this->sirisStorage->getHomeStorageId());
        $datasets = !empty($datasets) ? array_intersect($existingDatasets, $datasets) : $existingDatasets;

        foreach ($datasets as $dataset) {
            try {
                $this->cache->setEntry(
                    $dataset,
                    new ZfsCacheEntry(
                        $dataset,
                        0,
                        $this->getSnapshotUsedSizes($dataset)
                    )
                )->markEntry($dataset);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'CAC0001 Could not update zfs cache',
                    ['dataset' => $dataset, 'exception' => $e]
                );
            }
        }

        $this->cache->write();
    }

    /**
     * Add a single used size to the cache without triggering a full update.
     *
     * @param string $dataset
     * @param int $snapshot
     * @param int $usedSize
     */
    public function setCachedUsedSize(string $dataset, int $snapshot, int $usedSize): void
    {
        try {
            $this->readCache();

            $entry = $this->cache->getEntry($dataset) ?? new ZfsCacheEntry($dataset, 0, []);
            $entry->setUsedSize($snapshot, $usedSize);

            $this->cache->setEntry($dataset, $entry);
            $this->cache->markEntry($dataset);
            $this->cache->write();
        } catch (Throwable $e) {
            $this->logger->warning(
                'CAC0002 Could not add used size to zfs cache',
                ['dataset' => $dataset, 'snapshot' => $snapshot, 'exception' => $e]
            );
            throw $e;
        }
    }

    /**
     * Return the cached data if it exists
     *
     * @return ZfsCache
     */
    public function readCache(): ZfsCache
    {
        return $this->cache->read();
    }

    /**
     * Return true if the dataset exists
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $dataset
     * @return bool
     */
    public function exists(string $dataset): bool
    {
        return $this->storage->storageExists($dataset);
    }

    /**
     * Get the used size of all snapshots for a dataset.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $dataset
     * @return array
     */
    public function getSnapshotUsedSizes(string $dataset): array
    {
        $snapshotInfos = $this->storage->getSnapshotInfosFromStorage($dataset, true);
        $usedSizes = [];
        foreach ($snapshotInfos as $snapshotInfo) {
            $snapshotEpoch = $snapshotInfo->getTag();
            $usedSize = $snapshotInfo->getUsedSizeInBytes();

            $usedSizes[$snapshotEpoch] = $usedSize;
        }
        return $usedSizes;
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param string $dataset
     * @return string[]
     */
    public function getSnapshots(string $dataset): array
    {
        $snapshots = $this->storage->listSnapshotNames($dataset, true);
        $snapshots = array_filter($snapshots, function ($snapshot) {
            return is_numeric($snapshot);
        });
        return $snapshots;
    }

    /**
     * Get the 'usedbydataset' or 'usedds' value for a dataset
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $dataset
     * @return int
     */
    public function getUsedByDataset(string $dataset): int
    {
        $storageInfo = $this->storage->getStorageInfo($dataset);
        $usedByDataset = $storageInfo->getAllocatedSizeByStorageInBytes();
        return $usedByDataset === StorageInfo::STORAGE_SPACE_UNKNOWN ? 0 : $usedByDataset;
    }

    /**
     * Promote the clone at the given ZFS path.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $clonePath The ZFS path of the clone to promote
     */
    public function promoteClone(string $clonePath): void
    {
        $this->storage->promoteClone($clonePath);
    }

    /**
     * Does a destroy dry run and reports the results
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $name The ZFS path of the dataset to destroy
     * @param bool $recursive Do a recursive destroy (note that if false, this function will throw a
     * ProcessFailedException if there are any snapshots that belong to the clone)
     * @return bool True if the dataset can be destroyed, false otherwise
     */
    public function destroyDryRun(string $name, bool $recursive = false): bool
    {
        return $this->storage->canDestroyStorage($name, $recursive);
    }

    /**
     * Destroys the dataset with the given ZFS name.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $name The ZFS path of the dataset to destroy
     * @param bool $recursive Do a recursive destroy (note that if false, this function will throw a
     * ProcessFailedException if there are any snapshots that belong to the clone)
     */
    public function destroyDataset(string $name, bool $recursive = false): void
    {
        if (strpos($name, '@') !== false) {
            $this->storage->destroySnapshot($name);
        } else {
            $this->storage->destroyStorage($name, $recursive);
        }
    }

    /**
     * Retrieve the zfs filesystems.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string|null $parentDataset
     * @return array of zfs filesystems
     */
    public function getAllDatasetNames(string $parentDataset = null): array
    {
        $storageIds = $this->storage->listStorageIds($parentDataset ?? SirisStorage::PRIMARY_POOL_NAME);
        sort($storageIds);
        return $storageIds;
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param string $datasetName
     * @return bool
     */
    public function datasetExists(string $datasetName): bool
    {
        $allDatasets = $this->getAllDatasetNames();

        return in_array($datasetName, $allDatasets);
    }
}
