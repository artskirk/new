<?php

namespace Datto\Core\Storage\Zfs;

use Datto\Common\Resource\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Mount\MountUtility;
use Datto\Core\Storage\CloneCreationContext;
use Datto\Core\Storage\SnapshotCreationContext;
use Datto\Core\Storage\SnapshotInfo;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageException;
use Datto\Core\Storage\StorageInfo;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StoragePoolCreationContext;
use Datto\Core\Storage\StoragePoolDeviceStatus;
use Datto\Core\Storage\StoragePoolExpandDeviceContext;
use Datto\Core\Storage\StoragePoolExpansionContext;
use Datto\Core\Storage\StoragePoolImportContext;
use Datto\Core\Storage\StoragePoolInfo;
use Datto\Core\Storage\StoragePoolReductionContext;
use Datto\Core\Storage\StoragePoolReplacementContext;
use Datto\Core\Storage\StoragePoolStatus;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\System\ModuleManager;
use Datto\Util\RetryHandler;
use Datto\Utility\Block\Blockdev;
use Datto\Utility\Process\ProcessCleanup;
use Datto\Utility\Storage\Zfs;
use Datto\Utility\Storage\Zpool;
use Exception;
use Psr\Log\LoggerAwareInterface;

/**
 * This class is the storage interface to the zfs backend
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsStorage implements StorageInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SUPPORTED_TYPES = [StorageType::STORAGE_TYPE_FILE, StorageType::STORAGE_TYPE_BLOCK];
    private const ZVOL_DATASET_BASE_PATH = '/dev/zvol';
    private const ZFS_MODULE_PARAMETER_PATH = '/sys/module/zfs/parameters/';
    private const DEFAULT_ZVOL_SIZE = 16 * 1024 * 1024 * 1024 * 1024; // 16TiB
    private const ZFS_NOT_APPLICABLE_PROPERTY = '-';
    private const DATTO_LAST_SNAPSHOT_PROPERTY = 'datto:last_snapshot';

    private const ZPOOL_EXECUTION_ATTEMPTS = 5;
    private const ZPOOL_EXECUTION_WAIT_TIME_SECONDS = 5;

    private const RAID_NONE = 'none';
    private const RAID_MIRROR = 'mirror';
    private const RAID_5 = 'raidz1';
    private const RAID_6 = 'raidz2';
    private const RAID_MULTIPLE = 'multi';

    private const MINIMUM_RAID_MIRROR_DRIVES = 2;
    private const MINIMUM_RAID_5_DRIVES = 3;
    private const MINIMUM_RAID_6_DRIVES = 4;

    private const MAXIMUM_RAID_MIRROR_DRIVES = 2;

    private Zpool $zpool;
    private Zfs $zfs;
    private Filesystem $filesystem;
    private MountUtility $mountUtility;
    private Blockdev $blockdevUtility;
    private ProcessCleanup $processCleanup;
    private ModuleManager $moduleManager;
    private RetryHandler $retryHandler;
    private Sleep $sleep;
    private Collector $collector;

    /** @var string[] List of pools that must not be deleted */
    private array $protectedPools;

    /** @var string[] List of storages that must not be deleted */
    private array $protectedStorages;

    public function __construct(
        Zpool $zpool,
        Zfs $zfs,
        Filesystem $filesystem,
        MountUtility $mountUtility,
        Blockdev $blockdevUtility,
        ProcessCleanup $processCleanup,
        ModuleManager $moduleManager,
        RetryHandler $retryHandler,
        Sleep $sleep,
        Collector $collector,
        array $protectedPools = [],
        array $protectedStorages = []
    ) {
        $this->zpool = $zpool;
        $this->zfs = $zfs;
        $this->filesystem = $filesystem;
        $this->mountUtility = $mountUtility;
        $this->blockdevUtility = $blockdevUtility;
        $this->processCleanup = $processCleanup;
        $this->moduleManager = $moduleManager;
        $this->retryHandler = $retryHandler;
        $this->sleep = $sleep;
        $this->protectedPools = $protectedPools;
        $this->protectedStorages = $protectedStorages;
        $this->collector = $collector;
    }

    /**
     * STORAGE BACKEND METHODS
     */

    public function getGlobalProperty(string $property): string
    {
        $path = self::ZFS_MODULE_PARAMETER_PATH . $property;
        if ($this->filesystem->exists($path)) {
            $contents = $this->filesystem->fileGetContents($path);
            if ($contents !== false) {
                return trim($contents);
            }
        }
        return '';
    }

    /**
     * STORAGE POOL METHODS
     */

    public function listPoolIds(): array
    {
        return $this->zpool->listPools();
    }

    public function createPool(StoragePoolCreationContext $context): string
    {
        $poolName = $context->getName();
        $mountpoint = $context->getMountpoint();
        $fileSystemProperties = $context->getFileSystemProperties();

        // This may seem redundant, but it allows the public facing const to change values without having downstream side effects
        if ($mountpoint === StoragePoolCreationContext::DEFAULT_MOUNTPOINT) {
            $mountpoint = '';
        }

        $this->logger->info('ZFS0001 Creating pool', ['poolName' => $poolName, 'mountpoint' => $mountpoint]);
        $this->zpool->create($poolName, $context->getDrives(), $mountpoint, $fileSystemProperties);

        return $poolName;
    }

    public function destroyPool(string $poolId): void
    {
        $this->validateNotProtectedPool($poolId, 'destroying pool');
        $poolName = $this->getPoolName($poolId);
        $this->logger->info('ZFS0002 Destroying pool', ['poolName' => $poolName]);

        $this->zpool->destroy($poolName);
    }

    public function poolHasBeenImported(string $poolId): bool
    {
        return $this->zpool->isImported($poolId);
    }

    public function importPool(StoragePoolImportContext $context): string
    {
        $poolName = $context->getName();
        $this->logger->info('ZFS0003 Importing pool', ['poolName' => $poolName]);

        $this->zpool->import($poolName, $context->force(), $context->byId(), $context->getDevicePaths());

        return $poolName;
    }

    public function exportPool(string $poolId): void
    {
        $this->logger->info('ZFS0004 Exporting pool', ['poolId' => $poolId]);
        $this->zpool->export($poolId);
    }

    public function getPoolStatus(string $poolId, bool $fullDevicePath = false, bool $verbose = false): StoragePoolStatus
    {
        $parsedStatus = $this->zpool->getParsedStatus($poolId, $fullDevicePath, $verbose);

        $devices = [];
        $config = $parsedStatus['config'];
        if ($config) {
            // Remove the header line (NAME, STATE, READ, WRITE, CKSUM, NOTE)
            array_shift($config);

            $devices = $this->getStoragePoolDevices($config);
        }

        $storagePoolStatus = new StoragePoolStatus(
            $poolId,
            $parsedStatus['state'] ?? '',
            $parsedStatus['status'] ?? '',
            $parsedStatus['action'] ?? '',
            $parsedStatus['scan'] ?? '',
            $parsedStatus['errors'] ?? [],
            $devices
        );

        return $storagePoolStatus;
    }

    public function getPoolInfo(string $poolId): StoragePoolInfo
    {
        $poolName = $this->getPoolName($poolId);

        $spaceValues = $this->getPoolProperties($poolId, ['size', 'allocated', 'free','capacity','dedupratio','fragmentation']);

        $totalSizeInBytes = intval($this->getPropertyValue($spaceValues, 'size', StoragePoolInfo::POOL_SPACE_UNKNOWN));
        $allocatedSizeInBytes = intval($this->getPropertyValue($spaceValues, 'allocated', StoragePoolInfo::POOL_SPACE_UNKNOWN));
        $freeSpaceInBytes = intval($this->getPropertyValue($spaceValues, 'free', StoragePoolInfo::POOL_SPACE_UNKNOWN));
        $errorCount = $this->zpool->getErrorCount($poolName);
        $allocatedPercent = intval($this->getPropertyValue($spaceValues, 'capacity', StoragePoolInfo::ALLOCATED_PERCENTAGE_UNKNOWN));
        $dedupRatio = floatval($this->getPropertyValue($spaceValues, 'dedupratio', StoragePoolInfo::DEDUP_RATIO_UNKNOWN));
        $fragmentation = intval($this->getPropertyValue($spaceValues, 'fragmentation', StoragePoolInfo::FRAGMENTATION_UNKNOWN));
        $numberOfDisksInPool = $this->zpool->getNumberOfDisks($poolId);

        $storagePoolInfo = new StoragePoolInfo(
            $poolName,
            $poolId,
            self::SUPPORTED_TYPES,
            $totalSizeInBytes,
            $allocatedSizeInBytes,
            $freeSpaceInBytes,
            $errorCount,
            $allocatedPercent,
            $dedupRatio,
            $fragmentation,
            $numberOfDisksInPool
        );

        return $storagePoolInfo;
    }

    public function getPoolProperties(string $poolId, array $properties): array
    {
        $poolName = $this->getPoolName($poolId);
        return $this->zpool->getProperties($poolName, $properties);
    }

    public function setPoolProperties(string $poolId, array $properties): void
    {
        $context = array_merge(['poolId' => $poolId], $properties);
        $this->logger->info('ZFS0018 Setting properties on pool', $context);
        $poolName = $this->getPoolName($poolId);
        foreach ($properties as $key => $value) {
            $this->zpool->setProperty($poolName, $key, $value);
        }
    }

    public function expandPoolDeviceSpace(StoragePoolExpandDeviceContext $context): void
    {
        $this->logger->info('ZFS0000 Expanding pool device space', [
            'poolId' => $context->getName(),
            'poolDevice' => $context->getPoolDevice()
        ]);
        $this->zpool->expandPoolDevice($context->getName(), $context->getPoolDevice());
    }

    public function expandPoolSpace(StoragePoolExpansionContext $context): void
    {
        $newDriveIds = $context->getDrives();
        $raidLevel = $context->getRaidLevel();
        if ($raidLevel !== '') {
            $this->validateRaidLevel($raidLevel);
            $this->validateMinimumDriveCount($newDriveIds, $raidLevel);
            $this->validateMaximumDriveCount($newDriveIds, $raidLevel);
        }

        $this->clearZfsLabelsOnDrives($newDriveIds);

        $this->logger->info('ZFS0019 Expanding pool space', ['poolId' => $context->getName()]);
        $this->retryHandler->executeAllowRetry(
            function () use ($context) {
                $this->zpool->addDriveGroup(
                    $context->getName(),
                    $context->getDrives(),
                    $context->getRaidLevel(),
                    $context->isRaidRequired()
                );
            },
            static::ZPOOL_EXECUTION_ATTEMPTS,
            static::ZPOOL_EXECUTION_WAIT_TIME_SECONDS
        );
    }

    public function reducePoolSpace(StoragePoolReductionContext $context): void
    {
        $poolName = $context->getName();
        $driveIds = $context->getDrives();
        $this->logger->info('ZFS0026 Reducing pool space', ['poolId' => $poolName]);
        foreach ($driveIds as $driveId) {
            $this->retryHandler->executeAllowRetry(
                function () use ($poolName, $driveId) {
                    $this->zpool->detachDrive($poolName, $driveId);
                },
                static::ZPOOL_EXECUTION_ATTEMPTS,
                static::ZPOOL_EXECUTION_WAIT_TIME_SECONDS
            );
        }
    }

    public function replacePoolStorage(StoragePoolReplacementContext $context): void
    {
        $pool = $context->getName();
        $sourceDriveId = $context->getSourceDriveId();
        $targetDriveId = $context->getTargetDriveId();

        $this->clearZfsLabelsOnDrive($targetDriveId);

        $this->retryHandler->executeAllowRetry(
            function () use ($pool, $sourceDriveId, $targetDriveId) {
                $this->logger->info('ZPS0006 Attempting to replace drive...', ['source' => $sourceDriveId, 'target' => $targetDriveId]);
                $this->zpool->replaceDrive($pool, $sourceDriveId, $targetDriveId);
                $this->logger->info('ZPS0007 Drive replaced successfully and will start resilvering now.', ['source' => $sourceDriveId]);
            },
            static::ZPOOL_EXECUTION_ATTEMPTS,
            static::ZPOOL_EXECUTION_WAIT_TIME_SECONDS
        );
    }

    public function startPoolRepair(string $poolId): void
    {
        if ($this->isPoolRepairInProgress($poolId)) {
            return;
        }

        $this->logger->info('ZFS0021 Starting pool repair', ['poolId' => $poolId]);
        $this->zpool->scrub($poolId);
    }

    public function stopPoolRepair(string $poolId): void
    {
        if (!$this->isPoolRepairInProgress($poolId)) {
            return;
        }

        $this->logger->info('ZFS0022 Stopping pool repair', ['poolId' => $poolId]);
        $this->zpool->cancelScrub($poolId);
    }

    /**
     * STORAGE METHODS
     */

    public function listStorageIds(string $poolId): array
    {
        $poolName = $this->getPoolName($poolId);
        $storageNames = $this->zfs->listDatasetsAndZvolsInPool($poolName);
        return $storageNames;
    }

    public function listClonedStorageIds(string $poolId): array
    {
        $storageIds = $this->listStorageIds($poolId);

        $clonedStorageIds = [];
        foreach ($storageIds as $storageId) {
            $properties = $this->getStorageProperties($storageId, ['origin']);
            if ($properties['origin'] && $properties['origin'] !== self::ZFS_NOT_APPLICABLE_PROPERTY) {
                $clonedStorageIds[] = $storageId;
            }
        }
        return $clonedStorageIds;
    }

    public function storageExists(string $storageId): bool
    {
        return $this->zfs->exists($storageId);
    }

    public function createStorage(StorageCreationContext $context): string
    {
        $storageId = $context->getParentId() . '/' . $context->getName();
        $type = $context->getType();
        $this->validateStorageType($storageId, $type, 'creating storage');

        $createParents = $context->shouldCreateParents();
        $properties = $context->getProperties();
        $maxSizeInBytes = $context->getMaxSizeInBytes();

        if ($type === StorageType::STORAGE_TYPE_FILE) {
            if ($maxSizeInBytes !== StorageCreationContext::MAX_SIZE_DEFAULT) {
                $properties['quota'] = $maxSizeInBytes;
            }

            $this->logger->info('ZFS0005 Creating dataset', ['storageId' => $storageId, 'properties' => $properties]);
            $this->zfs->createDataset($storageId, $createParents, $properties);
        } elseif ($type === StorageType::STORAGE_TYPE_BLOCK) {
            if ($maxSizeInBytes === StorageCreationContext::MAX_SIZE_DEFAULT) {
                $maxSizeInBytes = self::DEFAULT_ZVOL_SIZE;
            }

            $this->logger->info('ZFS0006 Creating zvol', ['storageId' => $storageId, 'size' => $maxSizeInBytes]);
            $this->zfs->createZvolDataset($storageId, $maxSizeInBytes, $createParents);

            $storageInfo = $this->getStorageInfo($storageId);
            $blockDevice = $storageInfo->getBlockDevice();
            if ($this->filesystem->exists($blockDevice)) {
                $this->blockdevUtility->flushBuffers($blockDevice);
            }
        }

        return $storageId;
    }

    public function canDestroyStorage(string $storageId, bool $recursive = false) : bool
    {
        $this->validateNotProtectedStorage($storageId, 'destroying storage dry run');

        $storageInfo = $this->getStorageInfo($storageId);
        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE &&
            $storageInfo->isMounted()) {
            $mountpoint = $storageInfo->getFilePath();
            $this->processCleanup->logProcessesUsingDirectory($mountpoint, $this->logger);
        }

        $this->logger->info('ZFS0020 Destroying storage dry run', ['storageId' => $storageId]);
        return $this->zfs->destroyDryRun($storageId, $recursive);
    }

    public function destroyStorage(string $storageId, bool $recursive = false): void
    {
        $this->validateNotProtectedStorage($storageId, 'destroying storage');

        $storageInfo = $this->getStorageInfo($storageId);
        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE &&
            $storageInfo->isMounted()) {
            $mountpoint = $storageInfo->getFilePath();
            $this->processCleanup->logProcessesUsingDirectory($mountpoint, $this->logger);
        }

        $this->logger->info('ZFS0007 Destroying storage', ['storageId' => $storageId]);
        $this->zfs->destroyDataset($storageId, $recursive);
    }

    public function getStorageInfo(string $storageId): StorageInfo
    {
        $properties = $this->getStorageProperties($storageId, $this->getZfsStorageProperties());
        return $this->createStorageInfoFromProperties($storageId, $properties);
    }

    public function getStorageInfos(string $storageId, bool $recursive = false): array
    {
        $zfsProperties = array_merge(['name'], $this->getZfsStorageProperties());
        $storages = $this->zfs->getDatasets($storageId, $zfsProperties, $recursive);

        $storageInfos = [];
        foreach ($storages as $storage) {
            $storageId = $storage['name'];
            $storageInfos[] = $this->createStorageInfoFromProperties($storageId, $storage);
        }
        return $storageInfos;
    }

    public function getStorageProperties(string $storageId, array $properties): array
    {
        return $this->zfs->getProperties($storageId, $properties);
    }

    public function setStorageProperties(string $storageId, array $properties): void
    {
        $context = array_merge(['storageId' => $storageId], $properties);
        $this->logger->info('ZFS0023 Setting properties on storage', $context);
        foreach ($properties as $key => $value) {
            $this->zfs->setProperty($storageId, $key, $value);
        }
    }

    public function mountStorage(string $storageId): void
    {
        $this->logger->info('ZFS0024 Mounting storage', ['storageId' => $storageId]);
        $this->zfs->mount($storageId);
    }

    public function unmountStorage(string $storageId): void
    {
        $this->logger->info('ZFS0025 Unmounting storage', ['storageId' => $storageId]);
        $this->zfs->unmount($storageId);
    }

    /**
     * STORAGE SNAPSHOT METHODS
     */

    public function listSnapshotIds(string $storageId, bool $recursive): array
    {
        $storageInfo = $this->getStorageInfo($storageId);

        // If the dataset is mounted and has a valid mountpoint, we prefer to pull the list of snapshots
        // from the hidden zfs directory as it is much faster than retrieving large lists through zfs.
        if ($storageInfo->hasValidMountpoint()) {
            $snapshotIds = $this->getSnapshotIdsFromHiddenZfsDir($storageId, $storageInfo->getFilePath());
        } else {
            $snapshots = $this->zfs->getSnapshots($storageId, ['name'], $recursive);

            $snapshotIds = [];
            foreach ($snapshots as $snapshot) {
                $snapshotIds[] = $snapshot['name'];
            }
        }
        return $snapshotIds;
    }

    public function listSnapshotNames(string $storageId, bool $recursive): array
    {
        $snapshotIds = $this->listSnapshotIds($storageId, $recursive);
        $extractNameCallable = function (string $snapshotId): string {
            $delimiterPosition = strrpos($snapshotId, '@');
            if ($delimiterPosition === false) {
                return '';
            }
            return substr($snapshotId, $delimiterPosition + 1);
        };
        $snapshotNames = array_map($extractNameCallable, $snapshotIds);
        return $snapshotNames;
    }

    public function takeSnapshot(string $storageId, SnapshotCreationContext $context): string
    {
        $tag = $context->getTag();
        $this->logger->debug('ZFS0008 Taking snapshot', ['storageId' => $storageId, 'tag' => $tag]);
        $snapshotId = $this->zfs->takeSnapshot($storageId, $tag, $context->getTimeout());

        // Set the storage property to allow for quick lookups of last snapshot id
        $this->setStorageProperties($storageId, [self::DATTO_LAST_SNAPSHOT_PROPERTY => $snapshotId]);
        return $snapshotId;
    }

    public function destroySnapshot(string $snapshotId): void
    {
        $this->validateNotProtectedStorage($snapshotId, 'destroying snapshot');

        $this->logger->debug('ZFS0009 Destroying snapshot', ['snapshotId' => $snapshotId]);
        $this->zfs->destroyDataset($snapshotId, true);
    }

    public function getSnapshotInfo(string $snapshotId): SnapshotInfo
    {
        $properties = $this->getStorageProperties($snapshotId, $this->getZfsSnapshotProperties());
        return $this->createSnapshotInfoFromProperties($snapshotId, $properties);
    }

    public function getSnapshotInfosFromStorage(string $storageId, bool $recursive): array
    {
        $zfsProperties = array_merge(['name'], $this->getZfsSnapshotProperties());
        $snapshots = $this->zfs->getSnapshots($storageId, $zfsProperties, $recursive);

        $snapshotInfos = [];
        foreach ($snapshots as $snapshot) {
            $storageId = $snapshot['name'];
            $snapshotInfos[] = $this->createSnapshotInfoFromProperties($storageId, $snapshot);
        }
        return $snapshotInfos;
    }

    public function rollbackToLatestSnapshot(string $storageId): void
    {
        $lastSnapshotId = $this->getLatestSnapshotAndUpdateStorageProperty($storageId);
        if (!empty($lastSnapshotId)) {
            $this->logger->debug('ZFS0010 Rolling back to snapshot', ['snapshotId' => $lastSnapshotId]);
            $this->zfs->rollbackToSnapshot($lastSnapshotId, false, false);
        }
    }

    public function rollbackToSnapshotDestructive(string $snapshotId, bool $destroyClones): void
    {
        $this->logger->info('ZFS0027 Rolling back to snapshot with destroy', [
            'snapshotId' => $snapshotId,
            'destroyClones' => $destroyClones
        ]);
        $this->zfs->rollbackToSnapshot($snapshotId, !$destroyClones, $destroyClones);
    }

    public function cloneSnapshot(string $snapshotId, CloneCreationContext $context): string
    {
        $storageId = $this->getStorageIdFromSnapshotId($snapshotId);
        $this->validateNotProtectedStorage($storageId, 'cloning snapshot');

        $cloneId = $context->getParentId() . '/' . $context->getName();
        $this->validateNotProtectedStorage($cloneId, 'cloning snapshot');

        $mountPoint = $context->getMountPoint();

        $this->logger->debug(
            'ZFS0011 Cloning snapshot',
            ['snapshotId' => $snapshotId, 'cloneId' => $cloneId, 'mountPoint' => $mountPoint]
        );
        try {
            $this->zfs->cloneSnapshot($snapshotId, $cloneId, $mountPoint, $context->getSync());

            $this->validateClonedSnapshotMounted($cloneId, $snapshotId);
        } catch (Exception $e) {
            $this->logger->error(
                'ZFS0029 general zfs clone failure',
                ['exception' => $e, 'snapshotId' => $snapshotId, 'context' => json_encode($context)]
            );
            $this->collector->increment(Metrics::ZFS_CLONE_FAIL);

            if ($this->mountUtility->isMounted($mountPoint)) {
                $mountPointDevices = $this->mountUtility->getMountPointDeviceNames($mountPoint);
                $context = ['mountPoint' => $mountPoint, 'mountPointDevices' => $mountPointDevices];
                if ($this->mountUtility->unmount($mountPoint)) {
                    $this->logger->warning('ZFS0030 umount (repair) succeeded', $context);
                } else {
                    $this->logger->error('ZFS0031 failed to unmount', $context);
                }
            }
            throw $e;
        }

        return $cloneId;
    }

    public function promoteClone(string $cloneId): void
    {
        $this->validateNotProtectedStorage($cloneId, 'promoting clone');

        $this->logger->info('ZFS0012 Promoting clone', ['cloneId' => $cloneId]);
        $this->zfs->promoteClone($cloneId);
    }

    /**
     * Get the pool name from the id
     *
     * @param string $poolId Id of the pool to retrieve the name of
     * @return string Name of the pool
     */
    private function getPoolName(string $poolId): string
    {
        return $poolId;
    }

    /**
     * Determine if a pool repair is in progress.
     * If the pool id does not exist or the repair status cannot be determined, an exception will be thrown.
     *
     * @param string $poolId Id of the pool to get the status of
     * @return bool True if a pool repair is in progress, false otherwise
     */
    private function isPoolRepairInProgress(string $poolId): bool
    {
        $parsedZpoolStatus = $this->zpool->getParsedStatus($poolId, false);

        $inProgress = false;
        if (array_key_exists('scan', $parsedZpoolStatus) &&
            strpos($parsedZpoolStatus['scan'], Zpool::SCAN_STATUS_SCRUBBING) === 0) {
            $inProgress = true;
        }

        return $inProgress;
    }

    /**
     * Get the storage name from the id
     *
     * @param string $storageId Id of the storage to retrieve the name of
     * @return string Name of the storage
     */
    private function getStorageNameFromStorageId(string $storageId): string
    {
        $storageNameParts = explode('/', $storageId);
        return $storageNameParts[array_key_last($storageNameParts)] ?? '';
    }

    /**
     * Get the storage type of the given storage
     *
     * @param string $storageId Storage id
     * @param array $properties Map of property names to property values
     * @return string StorageType string
     */
    private function getStorageType(string $storageId, array $properties): string
    {
        $type = $properties['type'] ?? StorageType::STORAGE_TYPE_UNKNOWN;

        if ($type === 'filesystem') {
            $resultType = StorageType::STORAGE_TYPE_FILE;
        } elseif ($type === 'volume') {
            $resultType = StorageType::STORAGE_TYPE_BLOCK;
        } else {
            $resultType = $type;
        }

        $this->validateStorageType($storageId, $resultType, 'retrieving storage type');
        return $resultType;
    }

    /**
     * Get the mountpoint or device path from the storage
     *
     * @param string $storageId Storage id
     * @param string $type Type of storage (file or block)
     * @param array $properties Map of property names to property values
     * @return string File location for file storage. Device path for block storage.
     */
    private function getStorageLocation(string $storageId, string $type, array $properties): string
    {
        $this->validateStorageType($storageId, $type, 'retrieving storage location');

        if ($type === StorageType::STORAGE_TYPE_FILE) {
            $location = $this->getPropertyValue($properties, 'mountpoint', StorageInfo::STORAGE_LOCATION_UNKNOWN);
        } elseif ($type === StorageType::STORAGE_TYPE_BLOCK) {
            $location = realpath(self::ZVOL_DATASET_BASE_PATH . '/' . $storageId);
            if ($location === false) {
                $location = StorageInfo::STORAGE_LOCATION_UNKNOWN;
            }
        } else {
            throw new Exception('Invalid storage type');
        }

        return $location;
    }

    /**
     * Get the tag from the snapshot id
     *
     * @param string $snapshotId Snapshot Id to retrieve the tag from
     * @return string Snapshot tag
     */
    private function getTagFromSnapshotId(string $snapshotId): string
    {
        $delimiterPosition = strpos($snapshotId, '@');
        if ($delimiterPosition === false) {
            return '';
        }
        return substr($snapshotId, $delimiterPosition + 1);
    }

    /**
     * Get the storage id from the snapshot id
     *
     * @param string $snapshotId Snapshot Id to retrieve the storage id from
     * @return string Storage id
     */
    private function getStorageIdFromSnapshotId(string $snapshotId): string
    {
        $delimiterPosition = strpos($snapshotId, '@');
        if ($delimiterPosition === false) {
            return '';
        }
        return substr($snapshotId, 0, $delimiterPosition);
    }

    /**
     * Get snapshot ids from the hidden zfs directory of a mountpoint.
     * This method assumes the mountpoint is valid, it's up to the caller to validate if it is.
     *
     * @param string $mountpoint
     * @return string[]
     */
    private function getSnapshotIdsFromHiddenZfsDir(string $storageId, string $mountpoint): array
    {
        $mountpoint = rtrim($mountpoint, '/');

        $snapshotDirs = $this->filesystem->glob(sprintf(
            '%s/.zfs/snapshot/*',
            $mountpoint
        ));
        if ($snapshotDirs === false) {
            return [];
        }

        $snapshots = array_map('basename', $snapshotDirs);

        $prependStorageIdCallable = function (string $snapshot) use ($storageId): string {
            return $storageId . '@' . $snapshot;
        };
        $snapshotIds = array_map($prependStorageIdCallable, $snapshots);

        return array_values($snapshotIds);
    }

    /**
     * Return the property value from the array or the default if a given property is invalid
     * Zfs will return '-' for properties that are not applicable, so this method checks for those as well
     *
     * @return mixed Property value
     */
    private function getPropertyValue(array $properties, string $propertyKey, $default)
    {
        if (isset($properties[$propertyKey]) &&
            trim($properties[$propertyKey]) !== '' &&
            $properties[$propertyKey] !== self::ZFS_NOT_APPLICABLE_PROPERTY) {
            $propertyValue = $properties[$propertyKey];
        } else {
            $propertyValue = $default;
        }
        return $propertyValue;
    }

    /**
     * Get the list of zfs storage properties to retrieve
     *
     * @return string[] List of zfs properties
     */
    private function getZfsStorageProperties(): array
    {
        // These properties should match the ones consumed in 'createStorageInfoFromProperties'
        return [
            'type',
            'quota',
            'used',
            'usedbydataset',
            'usedbysnapshots',
            'available',
            'compressratio',
            'mounted',
            'mountpoint',
            'creation',
            'origin'
        ];
    }

    /**
     * Create a storage info from the given storage id and properties array
     *
     * @param string $storageId Storage id
     * @param array $properties Map of property names to property values
     * @return StorageInfo
     */
    private function createStorageInfoFromProperties(string $storageId, array $properties): StorageInfo
    {
        $storageName = $this->getStorageNameFromStorageId($storageId);
        $type = $this->getStorageType($storageId, $properties);
        $location = $this->getStorageLocation($storageId, $type, $properties);

        $quotaSizeInBytes = intval($this->getPropertyValue($properties, 'quota', StorageInfo::STORAGE_SPACE_UNKNOWN));
        $allocatedSizeInBytes = intval($this->getPropertyValue($properties, 'used', StorageInfo::STORAGE_SPACE_UNKNOWN));
        $allocatedSizeByStorageInBytes = intval($this->getPropertyValue($properties, 'usedbydataset', StorageInfo::STORAGE_SPACE_UNKNOWN));
        $allocatedSizeBySnapshotsInBytes = intval($this->getPropertyValue($properties, 'usedbysnapshots', StorageInfo::STORAGE_SPACE_UNKNOWN));
        $freeSpaceInBytes = intval($this->getPropertyValue($properties, 'available', StorageInfo::STORAGE_SPACE_UNKNOWN));
        $compressRatio = floatval(str_replace('x', '', $this->getPropertyValue($properties, 'compressratio', strval(StorageInfo::STORAGE_COMPRESS_RATIO_UNKNOWN))));
        $isMounted = ($this->getPropertyValue($properties, 'mounted', 'no') === 'yes');
        $creationTime = intval($this->getPropertyValue($properties, 'creation', 0));
        $parent = $this->getPropertyValue($properties, 'origin', StorageInfo::STORAGE_PARENT_NONE);

        return new StorageInfo(
            $storageName,
            $storageId,
            $type,
            $location,
            $quotaSizeInBytes,
            $allocatedSizeInBytes,
            $allocatedSizeByStorageInBytes,
            $allocatedSizeBySnapshotsInBytes,
            $freeSpaceInBytes,
            $compressRatio,
            $isMounted,
            $creationTime,
            $parent
        );
    }

    /**
     * Get the list of zfs snapshot properties to retrieve
     *
     * @return string[] List of zfs properties
     */
    private function getZfsSnapshotProperties(): array
    {
        // These properties should match the ones consumed in 'createSnapshotInfoFromProperties'
        return [
            'used',
            'written',
            'creation',
            'clones'
        ];
    }

    /**
     * Create a snapshot info from the given snapshot id and properties array
     *
     * @param string $snapshotId Snapshot id
     * @param array $properties Map of property names to property values
     * @return SnapshotInfo
     */
    private function createSnapshotInfoFromProperties(string $snapshotId, array $properties): SnapshotInfo
    {
        $tag = $this->getTagFromSnapshotId($snapshotId);
        $usedSizeInBytes = intval($this->getPropertyValue($properties, 'used', SnapshotInfo::SNAPSHOT_SPACE_UNKNOWN));
        $writtenSizeInBytes = intval($this->getPropertyValue($properties, 'written', SnapshotInfo::SNAPSHOT_SPACE_UNKNOWN));
        $creationEpochTime = intval($this->getPropertyValue($properties, 'creation', SnapshotInfo::SNAPSHOT_CREATION_UNKNOWN));
        $cloneIds = !empty($properties['clones']) ? explode(',', $properties['clones']) : [];

        $parentStorageId = $this->getStorageIdFromSnapshotId($snapshotId);

        $snapshotInfo = new SnapshotInfo(
            $snapshotId,
            $parentStorageId,
            $tag,
            $usedSizeInBytes,
            $writtenSizeInBytes,
            $creationEpochTime,
            $cloneIds
        );

        return $snapshotInfo;
    }

    /**
     * Clears all ZFS metadata on the drive specified by its ID.
     *
     * @param string $driveId
     */
    private function clearZfsLabelsOnDrive(string $driveId): void
    {
        $this->logger->info('ZPS0005 Wiping potential ZFS metadata on drive', ['drive' => $driveId]);
        $this->zpool->clearZfsLabelsOnDrive($driveId);
    }

    /**
     * Clears all ZFS metadata on the list of drives specified by their IDs.
     *
     * @param array $driveIds
     */
    private function clearZfsLabelsOnDrives(array $driveIds): void
    {
        foreach ($driveIds as $driveId) {
            $this->clearZfsLabelsOnDrive($driveId);
        }
    }

    /**
     * Convert the config section of the zpool status call into StoragePoolDeviceStatus objects.
     *
     * @param array $poolDevices Parsed configuration section from zpool status
     */
    private function getStoragePoolDevices(array $poolDevices): array
    {
        $storagePoolDevices = [];
        foreach ($poolDevices as $poolDevice) {
            $subDevices = [];
            if (array_key_exists('devices', $poolDevice)) {
                $subPoolDevices = $poolDevice['devices'];
                $subDevices = $this->getStoragePoolDevices($subPoolDevices);
            }

            $storagePoolDevices[] = new StoragePoolDeviceStatus(
                $poolDevice['name'],
                $poolDevice['state'],
                $poolDevice['read'],
                $poolDevice['write'],
                $poolDevice['cksum'],
                $poolDevice['note'],
                $subDevices
            );
        }
        return $storagePoolDevices;
    }

    /**
     * Get the most recent snapshot for a storage
     *
     * @param string $storageId Storage Id to get the most recent snapshot for
     * @return string Snapshot Id of the most recent snapshot, if one exists. Otherwise, an empty string.
     */
    private function getLatestSnapshotAndUpdateStorageProperty(string $storageId): string
    {
        $retrievedFromZfsProperty = false;

        // Scan by names in the hidden zfs directory. If all of the snapshot tags are epochs, it
        // is safe to use a name sort to get the latest snapshot.
        $latestSnapshotId = $this->getLatestSnapshotFromHiddenZfsDirIfOnlyEpochTags($storageId);

        // If a non-epoch tag exists, look for a last snapshot property
        if (empty($latestSnapshotId)) {
            $latestSnapshotId = $this->getLatestSnapshotFromProperty($storageId);
            if (!empty($latestSnapshotId)) {
                $retrievedFromZfsProperty = true;
            }
        }

        // If the property is not set, fall back to scanning all snapshots and comparing creation times
        if (empty($latestSnapshotId)) {
            $latestSnapshotId = $this->getLatestSnapshotByComparingCreationTimes($storageId);
        }

        if (!$retrievedFromZfsProperty && !empty($latestSnapshotId)) {
            // Update the storage property for next time this method is called for this storage
            $this->setStorageProperties($storageId, [self::DATTO_LAST_SNAPSHOT_PROPERTY => $latestSnapshotId]);
        }

        return $latestSnapshotId;
    }

    /**
     * Get the most recent snapshot from the hidden zfs directory. This will only return one if
     * all of the snapshot tags are epochs. If all of the snapshot tags are epochs, it
     * is safe to use a name sort to get the latest snapshot.
     *
     * @param string $storageId Storage Id to get the most recent snapshot for
     * @return string Snapshot Id of the most recent snapshot, if one exists. Otherwise, an empty string.
     */
    private function getLatestSnapshotFromHiddenZfsDirIfOnlyEpochTags(string $storageId): string
    {
        $latestSnapshotId = '';

        $storageInfo = $this->getStorageInfo($storageId);

        // If the dataset is mounted and has a valid mountpoint, we prefer to pull the list of snapshots
        // from the hidden zfs directory as it is much faster than retrieving large lists through zfs.
        if ($storageInfo->hasValidMountpoint()) {
            $snapshotIds = $this->getSnapshotIdsFromHiddenZfsDir($storageId, $storageInfo->getFilePath());

            if (!empty($snapshotIds)) {
                // Get the list of snapshots that fit the epoch tag regex
                $callback = function (string $snapshotId): bool {
                    return (bool)preg_match('/^.+@\d{10,}$/', $snapshotId);
                };
                $epochSnapshotIds =  array_filter($snapshotIds, $callback);

                // If there are only epoch times for snapshot names, these arrays will have the same number of elements
                if (count($snapshotIds) === count($epochSnapshotIds)) {
                    rsort($snapshotIds);
                    $latestSnapshotId = array_shift($snapshotIds);
                }
            }
        }

        return $latestSnapshotId;
    }

    /**
     * Get the most recent snapshot from the zfs property. If the property is not set, an empty string will
     * be returned.
     *
     * @param string $storageId Storage Id to get the most recent snapshot for
     * @return string Snapshot Id of the most recent snapshot, if one exists. Otherwise, an empty string.
     */
    private function getLatestSnapshotFromProperty(string $storageId): string
    {
        $latestSnapshotId = '';

        $properties = $this->getStorageProperties($storageId, [self::DATTO_LAST_SNAPSHOT_PROPERTY]);
        if ($properties[self::DATTO_LAST_SNAPSHOT_PROPERTY] &&
            $properties[self::DATTO_LAST_SNAPSHOT_PROPERTY] !== self::ZFS_NOT_APPLICABLE_PROPERTY) {
            $latestSnapshotId = $properties[self::DATTO_LAST_SNAPSHOT_PROPERTY];
        }

        return $latestSnapshotId;
    }

    /**
     * Get the most recent snapshot by comparing the creation times of all of the snapshots associated
     * with the storage. Use with caution as this function may not be performant on storages with many snapshots.
     *
     * @param string $storageId Storage Id to get the most recent snapshot for
     * @return string Snapshot Id of the most recent snapshot, if one exists. Otherwise, an empty string.
     */
    private function getLatestSnapshotByComparingCreationTimes(string $storageId): string
    {
        $latestSnapshotId = '';

        $snapshotInfos = $this->getSnapshotInfosFromStorage($storageId, true);

        $creationTime = 0;
        foreach ($snapshotInfos as $snapshotInfo) {
            if ($snapshotInfo->getCreationEpochTime() > $creationTime) {
                $creationTime = $snapshotInfo->getCreationEpochTime();
                $latestSnapshotId = $snapshotInfo->getId();
            }
        }

        return $latestSnapshotId;
    }

    /**
     * Validate that the provided type string is valid from this storage backend
     *
     * @param string $type Type string to validate
     */
    private function validateStorageType(string $storageId, string $type, string $operation): void
    {
        if (!in_array($type, self::SUPPORTED_TYPES)) {
            $message = 'Invalid storage type';
            $this->logger->error('ZFS0013 ' . $message, ['type' => $type, 'operation' => $operation]);
            throw new StorageException($message, $storageId, $operation, $type);
        }
    }

    /**
     * Validate that the provided pool id is not in the protected pool list
     *
     * @param string $poolId Pool id to check
     */
    private function validateNotProtectedPool(string $poolId, string $operation): void
    {
        $poolName = $this->getPoolName($poolId);
        if (in_array($poolName, $this->protectedPools)) {
            $message = 'Cannot run this operation on a protected pool';
            $this->logger->error('ZFS0014 ' . $message, ['poolName' => $poolName, 'operation' => $operation]);
            throw new StorageException($message, $poolId, $operation);
        }
    }

    /**
     * Validate that the provided storage id is not in the protected storage list
     *
     * @param string $storageId Storage id to check
     */
    private function validateNotProtectedStorage(string $storageId, string $operation): void
    {
        if (in_array($storageId, $this->protectedStorages)) {
            $message = 'Cannot run this operation on a protected storage';
            $this->logger->error('ZFS0015 ' . $message, ['storageId' => $storageId, 'operation' => $operation]);
            throw new StorageException($message, $storageId, $operation);
        }
    }

    /**
     * Validate a raid level.
     *
     * @param string $raidLevel
     */
    private function validateRaidLevel(string $raidLevel): void
    {
        $levels = [
            self::RAID_MIRROR,
            self::RAID_5,
            self::RAID_6
        ];

        if (!in_array($raidLevel, $levels)) {
            throw new StorageException(sprintf(
                'Invalid raid level. %s is not supported',
                $raidLevel
            ));
        }
    }

    /**
     * Validate minimum number of drives allowed at a particular raid level.
     *
     * @param string[] $driveIds
     * @param string $raidLevel
     */
    private function validateMinimumDriveCount(array $driveIds, string $raidLevel): void
    {
        $minimumDriveCounts = [
            self::RAID_MIRROR => self::MINIMUM_RAID_MIRROR_DRIVES,
            self::RAID_5 => self::MINIMUM_RAID_5_DRIVES,
            self::RAID_6 => self::MINIMUM_RAID_6_DRIVES
        ];

        $invalid = isset($minimumDriveCounts[$raidLevel]) && count($driveIds) < $minimumDriveCounts[$raidLevel];
        if ($invalid) {
            throw new StorageException(sprintf(
                'Invalid drive count. %s requires a minimum of %d drives',
                $raidLevel,
                $minimumDriveCounts[$raidLevel]
            ));
        }
    }

    /**
     * Validate maximum number of drives allowed at a particular raid level.
     *
     * @param string[] $driveIds
     * @param string $raidLevel
     */
    private function validateMaximumDriveCount(array $driveIds, string $raidLevel): void
    {
        $maximumDriveCounts = [
            self::RAID_MIRROR => self::MAXIMUM_RAID_MIRROR_DRIVES,
        ];

        $invalid = isset($maximumDriveCounts[$raidLevel]) && count($driveIds) > $maximumDriveCounts[$raidLevel];
        if ($invalid) {
            throw new StorageException(sprintf(
                'Invalid drive count. %s is limited to a maximum of %d drives',
                $raidLevel,
                $maximumDriveCounts[$raidLevel]
            ));
        }
    }

    /**
     * Validate the cloned id is mounted
     *
     * @param string $cloneId
     * @param string $snapshotId
     */
    private function validateClonedSnapshotMounted(string $cloneId, string $snapshotId): void
    {
        $properties = $this->getStorageProperties($cloneId, ['mounted']);
        if ($properties['mounted'] === 'no') {
            $message = 'Cloned snapshot mounted property is "no" implying clone failed to mount';

            $this->logger->error('ZFS0028 ' . $message, ['cloneId', $cloneId, 'snapshotId', $snapshotId]);
            $this->collector->increment(Metrics::ZFS_CLONE_NOT_MOUNTED);
            
            throw new StorageException(sprintf(
                'Failed to validate snapshot clone.  %s is not mounted.',
                $cloneId
            ));
        }
    }
}
