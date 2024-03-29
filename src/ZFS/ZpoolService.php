<?php

namespace Datto\ZFS;

use Datto\AppKernel;
use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StoragePoolExpansionContext;
use Datto\Core\Storage\StoragePoolImportContext;
use Datto\Core\Storage\StoragePoolReductionContext;
use Datto\Core\Storage\StoragePoolReplacementContext;
use Datto\Core\Storage\Zfs\ZfsStorage;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Service for interfacing with ZFS storage pools.
 *
 * @deprecated Use StorageInterface instead
 *
 * @author Andrew Cope <acope@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZpoolService
{
    public const HOMEPOOL = 'homePool';

    public const RAID_NONE = "none";
    public const RAID_MIRROR = "mirror";
    public const RAID_5 = "raidz1";
    public const RAID_6 = "raidz2";
    public const RAID_MULTIPLE = "multi";

    const ZFS_SEND_CORRUPTED_PATH = '/sys/module/zfs/parameters/zfs_send_corrupt_data';
    // note that this file is NOT migrated by upgradectl! Why are you upgrading a siris with a bad pool anyway?
    const ZFS_CORRUPTED_MODPROBE_DROPIN_PATH = '/etc/modprobe.d/datto_zfs_send_corrupt_data.conf';
    // phpcs:disable
    // This string is indented funny so that it writes out correctly to the file.
    const ZFS_OPTIONS_SEND_CORRUPTED_DATA =
'# This file was generated by OS2 to allow speedsync to send replicated
# datasets, even when the pool is corrupted.
options zfs zfs_send_corrupt_data=1
';

    private const MAX_IMPORT_ATTEMPTS = 5;

    // phpcs:enable

    private DeviceLoggerInterface $logger;
    private ZpoolStatusFactory $zfsPoolStatusFactory;
    private Sleep $sleep;
    private Filesystem $filesystem;
    private StorageInterface $storage;

    public function __construct(
        DeviceLoggerInterface $logger = null,
        ZpoolStatusFactory $zfsPoolStatusFactory = null,
        Sleep $sleep = null,
        Filesystem $filesystem = null,
        StorageInterface $storage = null
    ) {
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->zfsPoolStatusFactory = $zfsPoolStatusFactory ?? AppKernel::getBootedInstance()->getContainer()->get(ZpoolStatusFactory::class);
        $this->sleep = $sleep ?: new Sleep();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->storage = $storage ?? AppKernel::getBootedInstance()->getContainer()->get(ZfsStorage::class);
    }

    /**
     * Check if the specified pool is imported or not. The process will throw an
     * exception and fail if the pool is not imported, so the status is false then.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool The name of the pool
     * @return bool True if the pool reports online, false otherwise
     */
    public function isImported($pool)
    {
        return $this->storage->poolHasBeenImported($pool);
    }

    /**
     * Import the specified storage pool
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool The name of the pool to import
     * @param bool $force Whether or not to force import the pool, perhaps due to a bad export
     */
    public function import($pool, bool $force = false, bool $byId = false)
    {
        if ($this->isImported($pool)) {
            $this->logger->info('ZPS0009 Zpool already imported.');

            return;
        }

        $poolImportContext = new StoragePoolImportContext($pool, $force, $byId);

        $importAttempts = 0;
        while ($importAttempts < self::MAX_IMPORT_ATTEMPTS) {
            ++$importAttempts;
            $this->logger->info('ZPS0001 Attempting to import pool', ['poolName' => $pool, 'attempt' => $importAttempts]);
            try {
                $this->storage->importPool($poolImportContext);
                break;
            } catch (Exception $exception) {
                if ($importAttempts === self::MAX_IMPORT_ATTEMPTS) {
                    $this->logger->error('ZPS0003 Failed to import pool', ['poolName' => $pool, 'exception' => $exception]);

                    throw $exception;
                }
                $this->logger->warning('ZPS0004 Failed to import pool. Retrying...', ['poolName' => $pool]);
            }
            $this->sleep->sleep(1);
        }

        $this->logger->info('ZPS0002 Successfully imported pool', ['poolName' => $pool]);
    }

    /**
     * Export the specified storage pool
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool The name of the pool to export
     */
    public function export(string $pool)
    {
        if (!$this->isImported($pool)) {
            $this->logger->info('ZPS0011 Zpool already exported.');

            return;
        }

        try {
            $this->storage->exportPool($pool);
        } catch (Exception $exception) {
            $this->logger->error('ZPS0013 Failed to export pool', ['poolName' => $pool]);
            throw $exception;
        }

        $this->logger->info('ZPS0012 Successfully exported pool', ['poolName' => $pool]);
    }

    /**
     * Get the available space in a ZFS pool
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool Name of the pool to check
     * @return int bytes of available space
     */
    public function getFreeSpace($pool)
    {
        try {
            $storageInfo = $this->storage->getStorageInfo($pool);
            $freeSpace = $storageInfo->getFreeSpaceInBytes();
        } catch (Throwable $throwable) {
            $this->logger->warning('ZPS0014 Storage free space could not be retrieved', ['exception' => $throwable]);
            // ignore excpetion and return 0 for free space
            $freeSpace = 0;
        }
        return $freeSpace;
    }

    /**
     * Forces a zfs replace on the given pool.
     * First it initializes the destination drive to make sure there's no partition table on it.
     * Then it reattempts a few times until it succeeds or fails.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     * @param string $sourceDriveId
     * @param string $targetDriveId
     */
    public function forceReplaceDrive(string $pool, string $sourceDriveId, string $targetDriveId)
    {
        $replacementContext = new StoragePoolReplacementContext(
            $pool,
            $sourceDriveId,
            $targetDriveId
        );

        $this->storage->replacePoolStorage($replacementContext);
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     * @param string $driveId
     */
    public function detach(string $pool, string $driveId)
    {
        $this->logger->info('ZPS0008 Detaching drive from pool', ['poolName' => $pool, 'drive' => $driveId]);

        $reductionContext = new StoragePoolReductionContext(
            $pool,
            [$driveId]
        );

        $this->storage->reducePoolSpace($reductionContext);
    }

    /**
     * Forcefully adds one new mirror group of two devices to the specified pool.
     * It clears potential zfs metadata in the destination drives before attempting to add.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     * @param string[] $newDriveIds
     * @param string $raidLevel
     */
    public function forceAddDriveGroup(string $pool, array $newDriveIds, string $raidLevel)
    {
        $expansionContext = new StoragePoolExpansionContext(
            $pool,
            $newDriveIds,
            $raidLevel,
            true
        );

        $this->storage->expandPoolSpace($expansionContext);
    }

    /**
     * Gets the current status of the given zfs pool.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     * @return ZpoolStatus
     */
    public function getZpoolStatus(string $pool): ZpoolStatus
    {
        return $this->zfsPoolStatusFactory->create($pool);
    }

    /**
     * Begins a scrub on the specified pool, does nothing if a scrub
     * is already in progress.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     */
    public function startScrub(string $pool)
    {
        $this->storage->startPoolRepair($pool);
    }

    /**
     * Cancels a scrub on the specified pool, does nothing if
     * there is no scrub in progress.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     */
    public function cancelScrub(string $pool)
    {
        $this->storage->stopPoolRepair($pool);
    }

    /**
     * @deprecated Use StorageInterface instead
     *
     * @param string $pool
     * @return string
     */
    public function getRaidLevel(string $pool): string
    {
        $status = $this->getZpoolStatus($pool);
        return $status->getRaidLevel();
    }

    /**
     * Activates the zfs auto expand option on the given ZFS Pool.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $poolName
     */
    public function activateAutoExpand(string $poolName)
    {
        $this->storage->setPoolProperties($poolName, ['autoexpand' => 'on']);
    }

    /**
     * Disables the zfs auto expand option on the given ZFS Pool.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param string $poolName
     */
    public function deactivateAutoExpand(string $poolName)
    {
        $this->storage->setPoolProperties($poolName, ['autoexpand' => 'off']);
    }

    /**
     * Gets essential zpool properties (i.e., the ones return by zpool list).
     *
     * @deprecated Use StorageInterface instead
     *
     * @return ZpoolProperties|null
     */
    public function getZpoolProperties(string $pool)
    {
        try {
            $poolInfo = $this->storage->getPoolInfo($pool);
            $zpoolProperties = new ZpoolProperties(
                $poolInfo->getTotalSizeInBytes(),
                $poolInfo->getAllocatedSizeInBytes(),
                $poolInfo->getFreeSpaceInBytes(),
                $poolInfo->getAllocatedPercent(),
                $poolInfo->getDedupRatio(),
                $poolInfo->getFragmentation(),
                $poolInfo->getNumberOfDisksInPool()
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('ZPS0015 Zpool properties could not be retrieved', ['exception' => $throwable]);
            // ignore excpetion and return null
            $zpoolProperties = null;
        }

        return $zpoolProperties;
    }

    /**
     * Sets the pool corrupted bit, which controls whether the pool will allow the
     * creation of send files containing corrupted data.
     *
     * @deprecated Use StorageInterface instead
     *
     * @param bool $setCorrupted
     * @return void
     */
    public function setZpoolCorruptedBit(bool $setCorrupted)
    {
        $this->logger->debug('ZPS0010 Setting send corrupted bit', ['corrupted' => $setCorrupted]);

        $this->filesystem->filePutContents(self::ZFS_SEND_CORRUPTED_PATH, $setCorrupted ? '1' : '0');

        if ($setCorrupted) {
            $this->filesystem->filePutContents(self::ZFS_CORRUPTED_MODPROBE_DROPIN_PATH, self::ZFS_OPTIONS_SEND_CORRUPTED_DATA);
        } else {
            $this->filesystem->unlinkIfExists(self::ZFS_CORRUPTED_MODPROBE_DROPIN_PATH);
        }
    }

    /**
     * Gets the current state of the pool corrupted bit.
     *
     * @deprecated Use StorageInterface instead
     *
     * @return bool
     */
    public function getZpoolCorruptedBit(): bool
    {
        return (int)$this->filesystem->fileGetContents(self::ZFS_SEND_CORRUPTED_PATH) === 1;
    }
}
