<?php
namespace Datto\Cloud;

use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Billing\Service as BillingService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerFactory;
use Datto\Service\Registration\SshKeyService;
use Datto\Utility\Cloud\RemoteDestroyException;
use Datto\Utility\Cloud\SpeedSync as SpeedSyncUtility;
use Datto\Utility\File\LockFactory;
use Throwable;

/**
 * Represents the sync tool SpeedSync to sync data to an offsite server.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class SpeedSync
{
    const OFFSITE_QUEUED = 0;
    const OFFSITE_SYNCING = 1;
    const OFFSITE_SYNCED = 2;
    const OFFSITE_NONE = 3;
    const OFFSITE_PROCESSING = 4;
    const DEVICE_MIGRATION_LIST = '/datto/config/sync/deviceMigrations/migrationsList';
    const DEVICE_MIGRATION_ACTIVE_STATE = 'ACTIVE';
    const DEVICE_MIGRATION_SENDING_STATE = 'SENDING';
    const DEVICE_MIGRATION_FINISHING_STATE = 'FINISHING';

    const KEY_REMOTE_FILE = 'remote file';
    const KEY_REMOTE_ZFS = 'remote zfs';
    const KEY_CRITICAL = 'critical';

    const TARGET_CLOUD = 'cloud';
    const TARGET_NO_OFFSITE = 'noOffsite';

    const SPEEDSYNC_USER = 'speedsync';

    /** @var DeviceConfig */
    private $config;

    /** @var Filesystem */
    private $filesystem;

    /** @var SpeedSyncCache */
    private $cache;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SpeedSyncUtility */
    private $speedSyncUtility;

    /** @var BillingService */
    private $billingService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var bool */
    private $enableCache;

    public function __construct(
        DeviceConfig $config = null,
        Filesystem $filesystem = null,
        SpeedSyncCache $cache = null,
        DeviceLoggerInterface $logger = null,
        SpeedSyncUtility $speedSyncUtility = null,
        BillingService $billingService = null,
        AgentConfigFactory $agentConfigFactory = null,
        bool $enableCache = true
    ) {
        $this->config = $config ?: new DeviceConfig();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->cache = $cache ?: new SpeedSyncCache();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->speedSyncUtility = $speedSyncUtility ?: new SpeedSyncUtility(
            new ProcessFactory(),
            new LockFactory(),
            $this->filesystem,
            $this->logger
        );
        $this->billingService = $billingService ?: new BillingService();
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->enableCache = $enableCache;
    }

    /**
     * Refreshes the cache for all SpeedSync datasets
     *
     * @param array $paths
     */
    public function writeCache(array $paths = [])
    {
        $this->cache->setActions($this->getOffsiteActions());

        $realPaths = $this->getDatasets();

        if (!empty($paths)) {
            $paths = array_intersect($realPaths, $paths);
        } else {
            $paths = $realPaths;
        }

        foreach ($paths as $zfsPath) {
            try {
                $points = $this->speedSyncUtility->listSnapshots($zfsPath);

                $offsitePoints = $points[self::KEY_REMOTE_ZFS] ?? $this->getOffsitePoints($zfsPath);
                $criticalPoints = $points[self::KEY_CRITICAL] ?? $this->getCriticalPoints($zfsPath);
                $remoteReplicatingPoints = $points[self::KEY_REMOTE_FILE] ?? $this->getRemoteReplicatingPoints($zfsPath);
                $queuedPoints = $this->getQueuedPoints($zfsPath, $criticalPoints);

                $this->cache->setEntry(
                    $zfsPath,
                    new SpeedSyncCacheEntry(
                        $zfsPath,
                        0,
                        $offsitePoints,
                        $criticalPoints,
                        $remoteReplicatingPoints,
                        $queuedPoints,
                        $this->getRemoteUsedSize($zfsPath)
                    )
                )->markEntry($zfsPath);
            } catch (Throwable $e) {
                $this->logger->warning('CAC0002 Could not update speedsync cache.', ['path' => $zfsPath, 'exception' => $e]);
            }
        }

        $this->cache->write(function (SpeedSyncCache $cache) use ($realPaths) {
            $paths = array_keys($cache->getEntries());
            $stalePaths = array_diff($paths, $realPaths);

            foreach ($stalePaths as $path) {
                $cache->deleteEntry($path);
            }
        });
    }

    /**
     * Return the cached data if it exists
     *
     * @return SpeedSyncCache
     */
    public function readCache(): SpeedSyncCache
    {
        return $this->cache->read();
    }

    /**
     * Add a dataset to speedsync.
     *
     * @param string $zfsPath
     * @param string $offsiteTarget The device id of the target for offsiting of the $zfsPath. Passing null will use the datto cloud.
     * @return int
     */
    public function add($zfsPath, string $offsiteTarget)
    {
        if ($offsiteTarget === static::TARGET_NO_OFFSITE) {
            return 0;
        }

        if ($offsiteTarget === static::TARGET_CLOUD) {
            $result = $this->speedSyncUtility->addDataset($zfsPath);
        } else {
            $result = $this->speedSyncUtility->addDatasetToTarget($zfsPath, $offsiteTarget);
        }

        if ($this->canWriteCache($result)) {
            $this->writeCache([$zfsPath]);
        }

        return $result;
    }

    /**
     * Removes a zfs path from SpeedSync, stopping any mirroring it is doing for that dataset
     *
     * @param string $zfsPath string The path to the ZFS dataset
     * @return int|null Exit code of the SpeedSync process
     */
    public function remove($zfsPath)
    {
        $result = $this->speedSyncUtility->remove($zfsPath);

        if ($this->canWriteCache($result)) {
            $this->writeCache(["nopaths"]);
        }

        return $result;
    }

    /**
     * Returns a list of tracked datasets
     *
     * @return string[]
     */
    public function getDatasets(): array
    {
        try {
            $datasets = array_keys($this->speedSyncUtility->getJobs());
        } catch (Throwable $e) {
            $datasets = [];
        }

        return $datasets;
    }

    /**
     * Returns a list of active jobs by the sync tool.
     *
     * @return array Array of jobs
     */
    public function getJobs()
    {
        return $this->speedSyncUtility->getJobs();
    }

    public function checkTargetServerConnectivity(): bool
    {
        $targetData = $this->speedSyncUtility->getTargetInfo(true);
        $areAllSuccessful = true;

        // check connectivity status
        foreach ($targetData as $serverName => $serverData) {
            $connected = $serverData['connection'] ?? '';

            if ($connected !== 'success') {
                $this->logger->error('SYN4541 Device cannot connect to target server', ['serverName' => $serverName]);

                $areAllSuccessful = false;
            }
        }

        return $areAllSuccessful;
    }

    public function getTargetServerNames(): array
    {
        $targetData = $this->speedSyncUtility->getTargetInfo(false);

        return array_keys($targetData);
    }

    /**
     * Get a list of target addresses
     *
     * @return array
     */
    public function getTargetAddresses(): array
    {
        $targetData = $this->speedSyncUtility->getTargetInfo(false);
        $addressLocations = [];

        foreach ($targetData as $serverName => $serverData) {
            $addressLocations[] = $serverData['target_ip'];
        }

        return $addressLocations;
    }

    /**
     * @param string $zfsPath
     * @param DestroySnapshotReason $reason
     */
    public function remoteDestroy(string $zfsPath, DestroySnapshotReason $reason)
    {
        $reasonString = $this->getReasonString($reason);

        $this->logger->info('SYN4530 Sending speedsync remote destroy request.', ['path' => $zfsPath, 'reason' => $reasonString]);

        $result = $this->speedSyncUtility->remoteDestroy($zfsPath, $reasonString);

        if ($this->canWriteCache($result)) {
            $this->writeCache([$zfsPath]);
        }

        if ($result !== 0) {
            throw new RemoteDestroyException($result);
        }
    }

    /**
     * @param string $zfsPath
     * @return int
     */
    public function getRemoteUsedSize($zfsPath)
    {
        return $this->speedSyncUtility->getRemoteUsed($zfsPath);
    }

    /**
     * @param string $zfsPath
     * @param array|null $criticalPoints
     * @return int[]
     */
    public function getQueuedPoints($zfsPath, array $criticalPoints = null)
    {
        $queuedPoints = $this->speedSyncUtility->getLocalPoints($zfsPath);

        if ($criticalPoints === null) {
            $criticalPoints = $this->getCriticalPoints($zfsPath);
        }

        $offsitedPoints = $this->getOffsitePoints($zfsPath);

        return array_values(array_diff($queuedPoints, $criticalPoints, $offsitedPoints));
    }

    /**
     * Returns the latest critical point of a ZFS dataset as an int, or returns null if no critical point
     *
     * @param string $zfsPath path of ZFS dataset to get the latest critical point for
     * @return int integer value of the latest critical data point, or zero for no latest critical
     */
    public function getLatestCriticalPoint(string $zfsPath): int
    {
        try {
            $criticalPoints = $this->getCriticalPoints($zfsPath);
        } catch (Throwable $throwable) {
            $criticalPoints = [];
        }
        if (is_array($criticalPoints) && !empty($criticalPoints)) {
            return intval(array_pop($criticalPoints));
        }
        return 0;
    }

    /**
     * Returns an array containing the critical points of the given ZFS path.
     *
     * @param string $zfsPath Path to ZFS dataset.
     * @return int[] Array containing critical points.
     */
    public function getCriticalPoints($zfsPath)
    {
        return $this->speedSyncUtility->getCriticalPoints($zfsPath);
    }

    /**
     * Returns an array containing the offsite points of the given ZFS path.
     *
     * @param string $zfsPath Path to ZFS dataset.
     * @return int[] Array containing offsite points.
     */
    public function getOffsitePoints($zfsPath)
    {
        return $this->speedSyncUtility->getRemotePoints($zfsPath);
    }

    /**
     * Returns an array containing the current actions of speedsync
     *
     * @return array Array containing offsite actions.
     */
    public function getOffsiteActions()
    {
        return $this->speedSyncUtility->getActions();
    }

    /**
     * Returns an array containing the remote pending (currently being received a node) points of the given ZFS path.
     *
     * @param string $zfsPath Path to ZFS dataset.
     * @return int[] Array containing pending points.
     */
    public function getRemoteReplicatingPoints($zfsPath)
    {
        return $this->speedSyncUtility->getRemotePending($zfsPath);
    }

    /**
     * Sends the given snapshot of the given ZFS path offsite.
     *
     * Important: Before calling this method, be sure to check that the device
     * is not out-of-service.
     *
     * @param string $zfsPath ZFS path of a dataset.
     * @param int $snapshot Snapshot of dataset that is to be sent offsite.
     *
     * @return bool
     */
    public function sendOffsite($zfsPath, $snapshot): bool
    {
        $result = $this->speedSyncUtility->mirror($zfsPath, $snapshot);

        if ($this->canWriteCache($result)) {
            $this->writeCache([$zfsPath]);
        }

        return $result === 0;
    }

    /**
     * Returns true if the given ZFS dataset is already included for offsite,
     * false otherwise.
     *
     * @param string $zfsPath ZFS path of a dataset that is to be checked.
     * @return bool True if the given ZFS dataset is already included for offsite,
     * false otherwise.
     */
    public function isZfsDatasetIncluded($zfsPath)
    {
        return in_array($zfsPath, $this->getDatasets());
    }

    /**
     * Refresh speedsync (rebuild caches, synchronize remote and local metadata, etc).
     *
     * @param $zfsPath
     */
    public function refresh($zfsPath)
    {
        $this->speedSyncUtility->refresh($zfsPath);

        if ($this->enableCache) {
            $this->writeCache([$zfsPath]);
        }
    }

    /**
     * Refresh speedsync in the background for a particular dataset.
     */
    public function refreshBackground(string $zfsPath): void
    {
        $this->speedSyncUtility->refreshBackground($zfsPath);
    }

    /**
     * @return void
     */
    public function refreshAll(): void
    {
        $this->speedSyncUtility->refreshAll();
    }

    /**
     * Refresh of inbound and outbound dataset metadata based on vectors that exist in speedsync-master.
     */
    public function refreshInboundAndOutboundMetadata()
    {
        $this->speedSyncUtility->refreshVectorsInBackground();
    }

    /**
     * Check if a replication target value is a Peer target
     *
     * @param $target
     * @return bool
     */
    public static function isPeerReplicationTarget($target): bool
    {
        // a P2P target will look like a device ID (non zero integer)
        return filter_var($target, FILTER_VALIDATE_INT) > 0;
    }

    /**
     * Invoke ad-hoc migration to move zfs datasets locally
     *
     * @param string $host
     * @param string $port
     * @param string $sshUser
     * @param string $sshKeyFile
     * @param int $sourceDeviceId
     * @param string $zfsPath
     */
    public function migrateDataset(
        string $host,
        string $port,
        string $sshUser,
        string $sshKeyFile,
        int $sourceDeviceId,
        string $zfsPath
    ) {
        $this->speedSyncUtility->migrateDeviceDataset($host, $port, $sshUser, $sshKeyFile, $sourceDeviceId, $zfsPath);
    }

    /**
     * Is Device Migration currently active?
     * @return bool true if active, false if not active
     */
    public function isMigrationActive()
    {
        foreach ($this->getMigrationList() as $migrationVol) {
            if ($migrationVol['state'] === self::DEVICE_MIGRATION_ACTIVE_STATE ||
                $migrationVol['state'] === self::DEVICE_MIGRATION_SENDING_STATE ||
                $migrationVol['state'] === self::DEVICE_MIGRATION_FINISHING_STATE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Manually run speedsync cron
     */
    public function runCron()
    {
        $this->speedSyncUtility->cron();
    }

    /**
     * @param string $agentKey
     * @param int $value
     * @param DeviceLoggerInterface $logger
     */
    public function setSyncVolumeOption(string $agentKey, int $value, DeviceLoggerInterface $logger)
    {
        $config = $this->agentConfigFactory->create($agentKey);
        $logger->info('SYN4525 Starting setSyncVolumeOption');
        if ($config->has('offsiteControl')) {
            $offsite = json_decode($config->get('offsiteControl', ''), true);
            $fqdn = $config->getFullyQualifiedDomainName();
            $logger->info('SYN4526 Control file exists, decoding.', ['fqdn' => $fqdn]);
        } else {
            $offsite = [];
            $logger->warning('SYN4527 Control file does not exist, using an empty array');
        }
        $offsite['interval'] = $value;
        $logger->info('SYN4528 Writing out new control file');
        $config->set('offsiteControl', json_encode($offsite));
    }

    /**
     * @param string $sshKeyFile
     * @param string $targetDatasetPath
     * @param string $host
     * @param string $user
     * @param string $sourceDatasetPath
     */
    public function adhocReceiveDataset(
        string $sshKeyFile,
        string $targetDatasetPath,
        string $host,
        string $user,
        string $sourceDatasetPath
    ) {
        $this->speedSyncUtility->adhocReceiveDataset($sshKeyFile, $targetDatasetPath, $host, $user, $sourceDatasetPath);
    }

    /**
     * Return device migrations list
     *
     * @return mixed
     */
    private function getMigrationList()
    {
        $migrationsList = [];
        if ($this->filesystem->exists(self::DEVICE_MIGRATION_LIST)) {
            $migrationsList = json_decode($this->filesystem->fileGetContents(self::DEVICE_MIGRATION_LIST), true);
        }
        return $migrationsList;
    }

    /**
     * @param int $result
     * @return bool
     */
    private function canWriteCache(int $result): bool
    {
        return $this->enableCache && $result === 0;
    }

    /**
     * @param DestroySnapshotReason $sourceReason
     * @return string
     * @throws \Exception
     */
    private function getReasonString(DestroySnapshotReason $sourceReason) : string
    {
        switch ($sourceReason) {
            case DestroySnapshotReason::MANUAL():
                $sourceReasonString = 'user-delete';
                break;
            case DestroySnapshotReason::RETENTION():
                $sourceReasonString = 'retention';
                break;
            case DestroySnapshotReason::MIGRATION():
                $sourceReasonString = 'device-migration';
                break;
            default:
                // Famous last words: this case can never occur.
                throw new \Exception("Invalid source reason given for speedsync remote destroy: {$sourceReason->key()}.");
        }

        return $sourceReasonString;
    }
}
