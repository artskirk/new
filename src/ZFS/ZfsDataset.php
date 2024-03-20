<?php

namespace Datto\ZFS;

use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\SnapshotCreationContext;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Process\ProcessCleanup;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Basic object representing a ZFS dataset
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Andrew Cope <acope@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsDataset implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const BLANK_PROPERTY = '-';

    const MOUNTPOINT_NONE = 'none';
    const MOUNTPOINT_DASH = '-';
    const UUID_PROPERTY = 'datto:uuid';
    const SYNC_PROPERTY = 'sync';
    const SYNC_VALUE_STANDARD = 'standard';
    const SYNC_VALUE_DISABLED = 'disabled';

    private string $name;
    private string $mountPoint;
    private int $usedSpace;
    private int $usedSpaceBySnapshots;
    private float $compressionRatio;
    private int $availableSpace;
    private string $origin;
    private int $quota;
    private bool $mounted;

    private ProcessCleanup $processCleanup;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;

    public function __construct(
        string $name,
        string $mountPoint,
        int $usedSpace,
        int $usedSpaceBySnapshots,
        float $compressionRatio,
        int $availableSpace,
        string $origin,
        int $quota,
        bool $mounted,
        ProcessCleanup $processCleanup,
        StorageInterface $storage,
        SirisStorage $sirisStorage
    ) {
        $this->name = $name;
        $this->mountPoint = $mountPoint;
        $this->usedSpace = $usedSpace;
        $this->usedSpaceBySnapshots = $usedSpaceBySnapshots;
        $this->compressionRatio = $compressionRatio;
        $this->availableSpace = $availableSpace;
        $this->origin = $origin;
        $this->quota = $quota;
        $this->mounted = $mounted;
        $this->processCleanup = $processCleanup;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
    }

    /**
     * The ZFS dataset name.
     *
     * E.g homePool/home/agents/abc1234
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The ZFS dataset mount point.
     *
     * @return string
     */
    public function getMountPoint(): string
    {
        return $this->mountPoint;
    }

    /**
     * @return int
     */
    public function getUsedSpace(): int
    {
        return $this->usedSpace;
    }

    /**
     * @return int
     */
    public function getUsedSpaceBySnapshots(): int
    {
        return $this->usedSpaceBySnapshots;
    }

    /**
     * @return float
     */
    public function getCompressionRatio(): float
    {
        return $this->compressionRatio;
    }

    /**
     * @return int
     */
    public function getAvailableSpace(): int
    {
        return $this->availableSpace;
    }

    /**
     * The origin dataset (only applies to clones).  If not a clone, will return '-'
     *
     * @return string
     */
    public function getOrigin(): string
    {
        return $this->origin;
    }

    /**
     * @return int
     */
    public function getQuota(): int
    {
        return $this->quota;
    }

    /**
     * Create zfs dataset at mountpoint
     *
     * @deprecated Use StorageInterface instead
     */
    public function create(): void
    {
        $nameAndParentId = $this->sirisStorage->getNameAndParentIdFromStorageId($this->getName());

        $name = $nameAndParentId['name'];
        $parentId = $nameAndParentId['parentId'];
        $properties['mountpoint'] = $this->getMountPoint();

        $storageCreationContext = new StorageCreationContext(
            $name,
            $parentId,
            StorageType::STORAGE_TYPE_FILE,
            StorageCreationContext::MAX_SIZE_DEFAULT,
            false,
            $properties
        );

        $this->storage->createStorage($storageCreationContext);
    }

    /**
     * Is the ZFS dataset mounted.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param bool $fresh If true, re-query ZFS for the latest mounted value, otherwise, use the latest queried value.
     * @return bool
     * @throws ZfsDatasetException
     */
    public function isMounted(bool $fresh = true): bool
    {
        if (!$fresh) {
            return $this->mounted;
        }

        try {
            $storageInfo = $this->storage->getStorageInfo($this->getName());
        } catch (Throwable $throwable) {
            $message =
                'Could not get mount status of dataset ' . $this->getName() .
                ' at mountpoint ' . $this->getMountPoint() .
                ' [' . $throwable->getMessage() . ']';
            throw new ZfsDatasetException($message, $this);
        }
        $this->mounted = $storageInfo->isMounted();
        return $this->mounted;
    }

    /**
     * Check if a dataset has a mountable mountpoint.
     *
     * @return bool
     */
    public function isMountable(): bool
    {
        return !in_array($this->getMountPoint(), [
            self::MOUNTPOINT_DASH,
            self::MOUNTPOINT_NONE
        ]);
    }

    /**
     * Attempts to mount the zfs dataset.
     *
     * @deprecated Use StorageInterface instead
     *
     * No attempt is made if already mounted.
     */
    public function mount(): void
    {
        if ($this->isMounted()) {
            return;
        }

        try {
            $this->storage->mountStorage($this->getName());
        } catch (Throwable $throwable) {
            $message =
                'Could not mount dataset ' . $this->getName() .
                ' at mountpoint ' . $this->getMountPoint() .
                ' [' . $throwable->getMessage() . ']';
            throw new ZfsDatasetException($message, $this);
        }
    }

    /**
     * Attempts to unmount a zfs dataset.
     *
     * @deprecated Use StorageInterface instead
     *
     * No attempt is made if not already unmounted.
     */
    public function unmount(): void
    {
        if (!$this->isMounted()) {
            return;
        }

        $this->processCleanup->logProcessesUsingDirectory($this->getMountPoint(), $this->logger);

        try {
            $this->storage->unmountStorage($this->getName());
        } catch (Throwable $throwable) {
            $message =
                'Could not unmount dataset ' . $this->getName() .
                ' at mountpoint ' . $this->getMountPoint() .
                ' [' . $throwable->getMessage() . ']';
            throw new ZfsDatasetException($message, $this);
        }
    }

    /**
     * Get the list of snapshots.
     * By default, sorts by name in descending order as names are backup start timestamps
     *
     * @deprecated Use StorageInterface instead
     * @param bool $descending optional parameter that will change the sort order if false
     *
     * @return ZfsSnapshot[]
     */
    public function getSnapshots(bool $descending = true): array
    {
        try {
            $snapshotIds = $this->storage->listSnapshotIds($this->getName(), true);

            if ($descending) {
                // Sort descending (most recent snapshot first)
                rsort($snapshotIds);
            }

            $zfsSnapshots = [];
            foreach ($snapshotIds as $snapshotId) {
                $zfsSnapshots[] = new ZfsSnapshot($snapshotId);
            }
        } catch (Throwable $throwable) {
            throw new ZfsDatasetException(sprintf(
                'Failed to get list of ZFS snapshots: %s',
                $throwable->getMessage()
            ), $this);
        }

        return $zfsSnapshots;
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param int $snapshot
     * @return ZfsSnapshot
     */
    public function getSnapshot(int $snapshot): ZfsSnapshot
    {
        $zfsSnapshot = $this->findSnapshot($snapshot);

        if ($zfsSnapshot === null) {
            throw new ZfsDatasetException('Could not find snapshot: ' . $this->getName() . '@' . $snapshot, $this);
        }

        return $zfsSnapshot;
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param int $snapshot
     * @return ZfsSnapshot|null
     */
    public function findSnapshot(int $snapshot)
    {
        try {
            $snapshotInfo = $this->storage->getSnapshotInfo($this->getName() . '@' . $snapshot);
        } catch (Throwable $throwable) {
            return null;
        }

        return new ZfsSnapshot($snapshotInfo->getId());
    }

    /**
     * Take a snapshot of the dataset
     *
     * @deprecated Use StorageInterface instead
     *
     * @param int $snapshot
     */
    public function takeSnapshot(int $snapshot): void
    {
        $snapshotCreationContext = new SnapshotCreationContext($snapshot);
        $this->storage->takeSnapshot($this->getName(), $snapshotCreationContext);
    }

    /**
     * Destroys given snapshot
     *
     * @deprecated Use StorageInterface instead
     *
     * @param ZfsSnapshot $snapshot
     *
     */
    public function destroySnapshot(ZfsSnapshot $snapshot): void
    {
        $this->processCleanup->logProcessesUsingDirectory($this->getMountPoint(), $this->logger);

        try {
            $this->storage->destroySnapshot($snapshot->getFullName());
        } catch (Throwable $throwable) {
            throw new ZfsDatasetException(sprintf(
                'Failed to destroy snapshot: %s',
                $throwable->getMessage()
            ), $this);
        }
    }

    /**
     * Get used size of the dataset in bytes.
     *
     * @deprecated Use StorageInterface instead
     *
     * @return int -1 if can't get it
     */
    public function getUsedSize(): int
    {
        try {
            $storageInfo = $this->storage->getStorageInfo($this->getName());
            return $storageInfo->getAllocatedSizeInBytes();
        } catch (Throwable $throwable) {
            return -1;
        }
    }

    /**
     * Get the dataset's UUID via zfs properties
     *
     * @deprecated Use StorageInterface instead
     *
     * @return string|null UUID property of the dataset
     */
    public function getUuid()
    {
        $storageProperty = $this->storage->getStorageProperties($this->getName(), [self::UUID_PROPERTY]);
        $uuidProperty = $storageProperty[self::UUID_PROPERTY] ?? self::BLANK_PROPERTY;
        return ($uuidProperty === self::BLANK_PROPERTY) ? null : $uuidProperty;
    }

    /**
     * Set the dataset's UUID via zfs properties
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $uuid
     */
    public function setUuid(string $uuid): void
    {
        try {
            $this->storage->setStorageProperties($this->getName(), [self::UUID_PROPERTY => $uuid]);
        } catch (Throwable $throwable) {
            $output = $throwable->getMessage();
            throw new Exception(
                'Could not set attribute ' . self::UUID_PROPERTY . ' to ' . $uuid .
                ' on filesystem ' . $this->getName(),
                0,
                $throwable
            );
        }
    }
}
