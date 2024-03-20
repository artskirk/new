<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetInfoSyncService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\Agent\ArchiveService;
use Datto\Asset\LocalSettings;
use Datto\Asset\Retention;
use Datto\Asset\Serializer\WeeklyScheduleSerializer;
use Datto\Cloud\SpeedSync;
use Datto\Config\ConfigBackup;
use Datto\Feature\FeatureService;
use Datto\License\AgentLimit;
use Datto\Resource\DateTimeService;
use Exception;
use Throwable;

/**
 * This class contains the API endpoints for updating the local settings for assets.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author John Roland <jroland@datto.com>
 */
class Local extends AbstractAssetEndpoint
{
    protected WeeklyScheduleSerializer $scheduleSerializer;

    private FeatureService $featureService;

    private DateTimeService $dateTimeService;

    private AssetInfoSyncService $assetInfoSyncService;

    private SpeedSync $speedSync;

    private ConfigBackup $configBackup;

    private ArchiveService $archiveService;

    private AgentLimit $agentLimit;

    private EncryptionService $encryptionService;

    public function __construct(
        AssetService $service,
        WeeklyScheduleSerializer $scheduleSerializer,
        FeatureService $featureService,
        DateTimeService $dateTimeService,
        AssetInfoSyncService $assetInfoSyncService,
        SpeedSync $speedSync,
        ConfigBackup $configBackup,
        ArchiveService $archiveService,
        AgentLimit $agentLimit,
        EncryptionService $encryptionService
    ) {
        parent::__construct($service);
        $this->scheduleSerializer = $scheduleSerializer;
        $this->featureService = $featureService;
        $this->dateTimeService = $dateTimeService;
        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->speedSync = $speedSync;
        $this->configBackup = $configBackup;
        $this->archiveService = $archiveService;
        $this->agentLimit = $agentLimit;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Gets the backup schedule for asset..
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset to be created.
     * @return array An array containing asset name and the current schedule
     */
    public function getSchedule(string $name): array
    {
        $asset = $this->assetService->get($name);

        return array(
            'name' => $name,
            'schedule' => $this->scheduleSerializer->serialize($asset->getLocal()->getSchedule())
        );
    }

    /**
     * Retrieve the snapshot retention values for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_LOCAL_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of target asset to retrieve retention for
     * @return array
     */
    public function getRetention(string $name): array
    {
        $asset = $this->assetService->get($name);
        $retention = $asset->getLocal()->getRetention();

        return array(
            'name' => $asset->getName(),
            'retention' => array(
                'daily' => $retention->getDaily(),
                'weekly' => $retention->getWeekly(),
                'monthly' => $retention->getMonthly(),
                'keep' => $retention->getMaximum()
            )
        );
    }

    /**
     * Set the snapshot retention values for a asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(),
     *   "daily" = @Symfony\Component\Validator\Constraints\Choice(choices = {
     *     24, 48, 72, 96, 120, 144, 168, 336, 504, 744, 240000
     *     }),
     *   "weekly" = @Symfony\Component\Validator\Constraints\Range(min = 48, max = 240000),
     *   "monthly" = @Symfony\Component\Validator\Constraints\Range(min = 731, max = 240000),
     *   "keep" = @Symfony\Component\Validator\Constraints\Choice(choices = {
     *     24, 168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064, 43830, 52596, 61362, 240000
     *     }),
     * })
     * @param string $name Name of the asset
     * @param int $daily time to retain daily snapshots, in hours
     * @param int $weekly time to retain weekly snapshots, in hours
     * @param int $monthly time to retain monthly snapshots, in hours
     * @param int $keep maximum time to retain any snapshots for
     * @return array name and retention array for asset
     */
    public function setRetention(string $name, int $daily, int $weekly, int $monthly, int $keep): array
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        if (!$this->featureService->isSupported(FeatureService::FEATURE_CONFIGURABLE_LOCAL_RETENTION)) {
            throw new Exception("Configurable Local Retention is not available");
        }

        $asset = $this->assetService->get($name);

        if (!$this->hasPermissionToChangeRetention($asset)) {
            throw new Exception('User does not have permission to change retention');
        }

        return $this->setRetentionForAsset($asset, $daily, $weekly, $monthly, $keep);
    }

    /**
     * Set the snapshot retention values for all assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RETENTION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "daily" = @Symfony\Component\Validator\Constraints\Choice(choices = {
     *     24, 48, 72, 96, 120, 144, 168, 336, 504, 744, 240000
     *     }),
     *   "weekly" = @Symfony\Component\Validator\Constraints\Range(min = 168, max = 240000),
     *   "monthly" = @Symfony\Component\Validator\Constraints\Range(min = 731, max = 240000),
     *   "keep" = @Symfony\Component\Validator\Constraints\Choice(choices = {
     *     24, 168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064, 43830, 52596, 61362, 240000
     *     }),
     * })
     * @param int $daily time to retain daily snapshots, in hours
     * @param int $weekly time to retain weekly snapshots, in hours
     * @param int $monthly time to retain monthly snapshots, in hours
     * @param int $keep maximum time to retain any snapshots for
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array list containing name and retention array for each asset
     */
    public function setRetentionAll(int $daily, int $weekly, int $monthly, int $keep, ?string $type = null): array
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_CONFIGURABLE_LOCAL_RETENTION)) {
            throw new Exception("Feature Configurable Local Retention is not available");
        }

        $assets = $this->assetService->getAllActiveLocal($type);

        $status = [];
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("RET0003 Retention not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            $status[] = $this->setRetentionForAsset($asset, $daily, $weekly, $monthly, $keep);
        }

        return $status;
    }

    /**
     * Retrieve the snapshot interval for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @param string $name Name of target asset to retrieve interval for
     * @return array
     */
    public function getInterval(string $name): array
    {
        $asset = $this->assetService->get($name);

        return array(
            'name' => $asset->getName(),
            'interval' => $asset->getLocal()->getInterval()
        );
    }

    /**
     * Resume snapshot backups for asset defined by $name.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of target asset to pause snapshot backups for
     * @return array array with keys 'name' (string) and 'paused' (boolean)
     */
    public function unpause(string $name): array
    {
        $asset = $this->assetService->get($name);
        $this->logger->setAssetContext($name);

        if ($asset->isType(AssetType::AGENT)) {
            $willExceedLimit = $asset->getLocal()->isPaused() && !$this->agentLimit->canUnpauseAgent();
            if ($willExceedLimit) {
                throw new Exception('Cannot unpause this agent without exceeding the device\'s active agent limit.');
            }
        }

        $asset->getLocal()->setPaused(false);
        $this->assetService->save($asset);
        $this->syncAssetInfo($name);

        $this->logger->info('AGT0620 Agent backups unpaused'); // log code is used by device-web see DWI-2252

        return array(
            'name' => $name,
            'paused' => false,
        );
    }

    /**
     * Pause snapshot backups for asset defined by $name.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name name of target asset to pause snapshot backups for
     * @param int|null $duration length (in seconds) to pause backups for, default is null for indefinite
     * @return array array with keys 'name' (string) and 'paused' (boolean) and 'pauseUntil' (unix timestamp)
     */
    public function pause(string $name, ?int $duration = LocalSettings::DEFAULT_PAUSE_UNTIL): array
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($name);
        $this->logger->setAssetContext($name);

        $asset->getLocal()->setPaused(true);

        if (!is_null($duration)) {
            $asset->getLocal()->setPauseUntil(
                $this->dateTimeService->getTime() + $duration
            );
        }

        $this->assetService->save($asset);
        $this->syncAssetInfo($name);

        $this->logger->info('AGT0620 Agent backups paused'); // log code is used by device-web see DWI-2252

        return array(
            'name' => $name,
            'paused' => true,
            'pauseUntil' => $asset->getLocal()->getPauseUntil()
        );
    }

    /**
     * Resumes snapshot backups for all assets on device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array[] 2D array with keys for each asset on the device, each sub-array containing keys 'name' (string)
     * and 'paused' (boolean)
     */
    public function unpauseAll(?string $type = null): array
    {
        if ($type === AssetType::AGENT) {
            if (!$this->agentLimit->canUnpauseAllAgents()) {
                throw new Exception('Cannot unpause all agents without exceeding the device\'s active agent limit.');
            }
        }

        $assets = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("AGT0622 Snapshots not unpaused for sealed agent", ["name" => $asset->getName()]);
                continue;
            }

            $asset->getLocal()->setPaused(false);
            $this->assetService->save($asset);

            $this->logger->setAssetContext($asset->getKeyName());
            $this->logger->info('AGT0620 Agent backups unpaused'); // log code is used by device-web see DWI-2252

            $status[] = array(
                'name' => $asset->getName(),
                'paused' => false
            );
        }

        return $status;
    }

    /**
     * Pauses snapshot backups for all assets on device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array[] 2D array with keys for each asset on the device, each sub-array containing keys 'name' (string)
     * and 'paused' (boolean)
     */
    public function pauseAll(?string $type = null): array
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = array();
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("AGT0622 Snapshots not paused for sealed agent", ["name" => $asset->getName()]);
                continue;
            }

            $asset->getLocal()->setPaused(true);
            $this->assetService->save($asset);

            $this->logger->setAssetContext($asset->getKeyName());
            $this->logger->info('AGT0620 Agent backups paused'); // log code is used by device-web see DWI-2252

            $status[] = array(
                'name' => $asset->getName(),
                'paused' => true,
            );
        }

        return $status;
    }

    /**
     * Pause backups when the agent is on a metered connection.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "pause" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     * @param string $name
     * @param bool $pause
     * @return array
     */
    public function setPauseWhileMetered(string $name, bool $pause): array
    {
        $asset = $this->assetService->get($name);
        $asset->getLocal()->setPauseWhileMetered($pause);
        $this->assetService->save($asset);

        return [
            'name' => $name,
            'pauseWhileMetered' => $asset->getLocal()->isPauseWhileMetered()
        ];
    }

    /**
     * Set the maximum bandwidth that an agent should use for backing up.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $name
     * @param int|null $bandwidthInBits
     * @return array
     */
    public function setMaximumBandwidth(string $name, ?int $bandwidthInBits = null): array
    {
        $asset = $this->assetService->get($name);
        $asset->getLocal()->setMaximumBandwidth($bandwidthInBits);
        $this->assetService->save($asset);

        return [
            'name' => $asset->getName(),
            'maximumBandwidthInBits' => $asset->getLocal()->getMaximumBandwidthInBits()
        ];
    }

    /**
     * Set the maximum throttled bandwidth that an agent should use for backing up.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(type="agent")
     * })
     * @param string $name
     * @param int|null $bandwidthInBits
     * @return array
     */
    public function setMaximumThrottledBandwidth(string $name, int $bandwidthInBits = null): array
    {
        $asset = $this->assetService->get($name);
        $asset->getLocal()->setMaximumThrottledBandwidth($bandwidthInBits);
        $this->assetService->save($asset);

        return [
            'name' => $asset->getName(),
            'maximumThrottledBandwidthInBits' => $asset->getLocal()->getMaximumThrottledBandwidthInBits()
        ];
    }

    /**
     * Set flag for whether or not a migration process is occuring for an agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "expedited" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     * @param string $name
     * @param bool $expedited
     *
     * @return array
     */
    public function setMigrationExpedited(string $name, bool $expedited): array
    {
        $asset = $this->assetService->get($name);
        $asset->getLocal()->setMigrationExpedited($expedited);
        $this->assetService->save($asset);

        return [
            'name' => $name,
            'expedited' => $asset->getLocal()->isMigrationExpedited()
        ];
    }

    /**
     * Sets a flag indicating whether or not an asset is migrating.
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresFeature("FEATURE_DIRECT_TO_CLOUD_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(type="agent"),
     *   "isPaused" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     */
    public function setMigrationInProgress(string $name, bool $isMigrationInProgress): array
    {
        $asset = $this->assetService->get($name);
        $asset->getLocal()->setMigrationInProgress($isMigrationInProgress);
        $this->assetService->save($asset);

        $this->syncAssetInfo($name);

        return [
            'name' => $name,
            'migrationInProgress' => $asset->getLocal()->isMigrationInProgress()
        ];
    }

    /**
     * Archive snapshot backups for asset defined by $assetKeyName.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSET_ARCHIVAL")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ARCHIVE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetKeyName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $assetKeyName name of target asset to archive
     */
    public function archive(string $assetKeyName): void
    {
        if ($this->encryptionService->isAgentSealed($assetKeyName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $this->archiveService->archive($assetKeyName);
    }

    /**
     * Return the epoch times of the Local recovery points for this agent.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @param string $name
     * @return int[]
     */
    public function getSnapshots(string $name): array
    {
        $asset = $this->assetService->get($name);
        return $asset->getLocal()->getRecoveryPoints()->getAllRecoveryPointTimes();
    }

    /**
     * Return the epoch times of the local recovery points for the given assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "names" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     *
     * @param string[] $names Key names of the assets to retrieve snapshots for
     * @return array[] List of snapshots with the asset key name as the key
     * [
     *   ["a8d97b0dd9164184b8056c99d5b0e58e"] => [
     *     'snapshots' => [[0] => 1538699021],
     *     'lastCriticalPoint' => 0
     *   ],
     *   ["839c6c8ae7ee45b99653cf9f860944a3"] => [
     *     'snapshots' => [
     *         [0] => 1538766458,
     *         [1] => 1538971208,
     *         [2] => 1538974812,
     *         [3] => 1538978409
     *     ],
     *     'lastCriticalPoint' => 1538974812
     *   ]
     * ]
     */
    public function getAllSnapshotsForAssetsByName(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            if ($name === ConfigBackup::KEYNAME) {
                $path = ConfigBackup::ZFS_PATH;
                $snapshots = $this->configBackup->getSnapshotEpochs();
            } else {
                $asset = $this->assetService->get($name);
                $path = $asset->getDataset()->getZfsPath();
                $snapshots = $asset->getLocal()->getRecoveryPoints()->getAllRecoveryPointTimes();
            }

            try {
                $lastCritical = $this->speedSync->getLatestCriticalPoint($path);
            } catch (Throwable $e) {
                // If we can't understand speedsync's response, it often means there are no offsite points.
                $lastCritical = 0;
            }

            $result[$name] = [
                'snapshots' => $snapshots,
                'lastCriticalSnapshot' => $lastCritical,
            ];
        }
        return $result;
    }

    /**
     * Sets a backup schedule and interval for asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\AssetExists(),
     *   "interval" = @Symfony\Component\Validator\Constraints\Choice(choices = {"5", "10", "15", "30", "60"})
     * })
     * @param string $name Name of the asset to be created.
     * @param array $newSchedule A new schedule to set (7x24 table with bool or 1/0)
     * @param int $interval Interval to set, either 5, 10, 15, 30 or 60 minutes
     * @return array An array containing asset name, saved schedule, interval, and backup count
     */
    public function setScheduleAndInterval(string $name, array $newSchedule, int $interval): array
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($name);

        return $this->setScheduleForAsset($asset, $newSchedule, $interval);
    }

    /**
     * Sets a backup schedule and interval for asset
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "interval" = @Symfony\Component\Validator\Constraints\Choice(choices = {"5", "10", "15", "30", "60"})
     * })
     * @param array $newSchedule A new schedule to set (7x24 table with bool or 1/0)
     * @param int $interval Interval to set, either 5, 10, 15, 30 or 60 minutes
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array An array containing asset name, saved schedule, interval, and backup count
     */
    public function setScheduleAndIntervalAll(array $newSchedule, int $interval, ?string $type = null): array
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = [];
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("SCI0001 Schedule/Interval not changed for sealed agent", ["name" => $asset->getName()]);
                continue;
            }

            $status[] = $this->setScheduleForAsset($asset, $newSchedule, $interval);
        }

        return $status;
    }

    /**
     * Enable or Disable ransomware checks for a given asset.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "enabled" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     *
     * @param string $name Name of target asset to enable or disable ransomware checks
     * @param bool $enabled Whether to enable ransomware checks
     * @return array
     */
    public function setRansomwareCheckEnabled(string $name, bool $enabled): array
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($name);
        $localSettings = $asset->getLocal();

        $enabled ? $localSettings->enableRansomwareCheck() : $localSettings->disableRansomwareCheck();
        $this->assetService->save($asset);

        return [
            'name' => $name,
            'enabled' => $enabled
        ];
    }

    /**
     * Enable or Disable ransomware checks for all assets.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "enabled" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     * @param bool $enabled Whether to enable ransomware checks
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array
     */
    public function setRansomwareCheckEnabledAll(bool $enabled, ?string $type = null): array
    {
        $status = [];
        $assets = $this->assetService->getAllActiveLocal($type);

        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("RCE0001 Ransomware check not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            $localSettings = $asset->getLocal();
            $enabled ? $localSettings->enableRansomwareCheck() : $localSettings->disableRansomwareCheck();
            $this->assetService->save($asset);

            $status[] = [
                'agentKey' => $asset->getKeyName(),
                'name' => $asset->getName(),
                'enabled' => $enabled
            ];
        }

        return $status;
    }

    /**
     * Enable or Disable agent integrity checks for a given asset.
     *
     * FIXME This should be in v1/device/asset/agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_FILESYSTEM_INTEGRITY_CHECK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Datto\App\Security\Constraints\AssetExists(),
     *   "enabled" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     *
     * @param string $agentKey
     * @param bool $enabled
     * @return array returns array on success, throws exception otherwise.
     */
    public function setIntegrityCheckEnabled(string $agentKey, bool $enabled): array
    {
        if ($this->encryptionService->isAgentSealed($agentKey)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($agentKey);

        return $this->setIntegrityCheckForAsset($asset, $enabled);
    }

    /**
     * Enable or Disable agent integrity checks for all assets.
     *
     * FIXME This should be in v1/device/asset/agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_FILESYSTEM_INTEGRITY_CHECK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "enabled" = @Symfony\Component\Validator\Constraints\Type(type="bool")
     * })
     * @param bool $enabled
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array returns array on success, throws exception otherwise.
     */
    public function setIntegrityCheckEnabledAll(bool $enabled, string $type = null): array
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = [];
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("ICE0001 Integrity check not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            $status[] = $this->setIntegrityCheckForAsset($asset, $enabled);
        }

        return $status;
    }

    /**
     * Retrieves whether or not integrity check is enabled for a given agent.
     *
     * FIXME This should be in v1/device/asset/agent
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_FILESYSTEM_INTEGRITY_CHECK")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $agentKey
     * @return bool
     */
    public function isIntegrityCheckEnabled(string $agentKey): bool
    {
        $asset = $this->assetService->get($agentKey);
        $localSettings = $asset->getLocal();

        return $localSettings->isIntegrityCheckEnabled();
    }

    /**
     * Retrieves whether or not ransomware check is enabled for a given agent.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "agentKey" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @param string $agentKey
     * @return bool
     */
    public function isRansomwareCheckEnabled(string $agentKey): bool
    {
        $asset = $this->assetService->get($agentKey);
        $localSettings = $asset->getLocal();

        return $localSettings->isRansomwareCheckEnabled();
    }

    /**
     * Set the end-time for ransomware alert suspension. From now until that time (if it is in the future), alerts
     * and UI warnings will be suppressed for the given asset, but ransomware tests will continue to run and report
     * their results to the cloud log.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "epochTime" = @Symfony\Component\Validator\Constraints\Type(type="int")
     * })
     *
     * @param string $name The asset's keyname
     * @param int $epochTime The epoch time, in seconds, at which to lift the ransomware alert suspension
     * @return array containing the keys 'name' and 'ransomwareSuspensionEndTime', corresponding to the asset name
     * and the asset's ransomware suspension end-time.
     */
    public function setRansomwareSuspensionEndTime(string $name, int $epochTime): array
    {
        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($name);
        $localSettings = $asset->getLocal();

        $localSettings->setRansomwareSuspensionEndTime($epochTime);
        $this->assetService->save($asset);

        return array(
            'name' => $name,
            'ransomwareSuspensionEndTime' => $epochTime
        );
    }

    /**
     * Get the end-time for ransomware alert suspension for the given asset.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name The asset's keyname
     * @return array containing the keys 'name' and 'ransomwareSuspensionEndTime', corresponding to the asset name
     * and the asset's ransomware suspension end-time.
     */
    public function getRansomwareSuspensionEndTime(string $name): array
    {
        $asset = $this->assetService->get($name);
        $localSettings = $asset->getLocal();

        return array(
            'name' => $name,
            'ransomwareSuspensionEndTime' => $localSettings->getRansomwareSuspensionEndTime()
        );
    }

    /**
     * Report a ransomware false positive for the given asset and set its ransomware suspension end time given the
     * requested suspension duration.
     *
     * FIXME This should be in the v1/device/asset/agent namespace
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_RANSOMWARE_DETECTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "suspensionDuration" = @Symfony\Component\Validator\Constraints\Type(type="int"),
     *   "snapshotEpoch" = @Symfony\Component\Validator\Constraints\Type(type="int"),
     * })
     * @param string $name The asset's keyname
     * @param int $suspensionDuration The amount of time, in seconds, to suspend ransomware alerts for the asset
     * @param int|null $snapshotEpoch The epoch time of the snapshot for which a false positive is being reported. If
     * this is left null, the epoch time of the most recent snapshot that has been flagged for ransomware for the given
     * asset will be used instead.
     * @param int|null $currentTime The current time. Mainly provided as an injectable parameter for unit testing; if
     * this is left null, the current time will be determined by the function.
     * @return array containing the keys 'name', 'ransomwareSuspensionEndTime', and 'snapshotEpoch', corresponding to
     * the asset name, the asset's ransomware suspension end-time, and the snapshot epoch that was reported as being
     * a false positive.
     */
    public function reportRansomwareFalsePositive(
        string $name,
        int $suspensionDuration,
        ?int $snapshotEpoch = null,
        ?int $currentTime = null
    ): array {
        $asset = $this->assetService->get($name);
        $localSettings = $asset->getLocal();

        $this->logger->setAssetContext($name);

        if ($currentTime === null) {
            $currentTime = time();
        }
        if ($snapshotEpoch === null) {
            $snapshotEpoch = $localSettings->getRecoveryPoints()->getMostRecentPointWithRansomware()->getEpoch();
        }

        $suspensionEndTime = $currentTime + $suspensionDuration;
        $localSettings->setRansomwareSuspensionEndTime($suspensionEndTime);
        $this->assetService->save($asset);

        $this->logger->setAssetContext($name);
        $this->logger->info('LOC0001 Ransomware false positive reported for asset.', ['snapshot' => $snapshotEpoch]);

        return array(
            'name' => $name,
            'ransomwareSuspensionEndTime' => $suspensionEndTime,
            'snapshotEpoch' => $snapshotEpoch
        );
    }

    private function hasPermissionToChangeRetention(Asset $asset): bool
    {
        $agentAddedRecently = $asset->getDateAdded() + DateTimeService::SECONDS_PER_DAY > $this->dateTimeService->getTime();

        // Permission to create an agent is less restricted than permission to change retention. However, agent pairing
        // needs to be able to change retention so we allow it if the agent isn't very old yet.
        return $this->isGranted("PERMISSION_RETENTION_WRITE") ||
            ($this->isGranted("PERMISSION_AGENT_CREATE") && $agentAddedRecently);
    }

    private function syncAssetInfo(string $assetKey): void
    {
        try {
            $this->assetInfoSyncService->sync($assetKey);
        } catch (Throwable $e) {
            $this->logger->setAssetContext($assetKey);
            $this->logger->error('AGT0621 Error syncing asset info', ['exception' => $e]);
        }
    }

    private function setScheduleForAsset(Asset $asset, array $schedule, int $interval): array
    {
        $this->logger->setAssetContext($asset->getKeyName());

        $asset->getLocal()->getSchedule()->setSchedule($schedule);
        $asset->getOffsite()->getSchedule()->filter($asset->getLocal()->getSchedule());

        $asset->getLocal()->setInterval($interval);
        $this->assetService->save($asset);
        $backupCount = $asset->getLocal()->getSchedule()->calculateBackupCount($interval);

        $context = [
            'keyName' => $asset->getKeyName(),
            'name' => $asset->getName(),
            'schedule' => $this->scheduleSerializer->serialize($asset->getLocal()->getSchedule()),
            'interval' => $interval,
            'backupCount' => $backupCount
        ];

        $this->logger->info('SCH0001 Backup schedule or interval changed', $context); // log code is used by device-web see DWI-2252

        return $context;
    }

    private function setRetentionForAsset(Asset $asset, int $daily, int $weekly, int $monthly, int $keep): array
    {
        $this->logger->setAssetContext($asset->getKeyName());

        $retention = new Retention($daily, $weekly, $monthly, $keep);
        $asset->getLocal()->setRetention($retention);
        $this->assetService->save($asset);

        $context = [
            'keyName' => $asset->getKeyName(),
            'name' => $asset->getName(),
            'retention' => [
                'daily' => $retention->getDaily(),
                'weekly' => $retention->getWeekly(),
                'monthly' => $retention->getMonthly(),
                'keep' => $retention->getMaximum()
            ]
        ];

        $this->logger->info('RET0001 Changed local retention', $context); // log code is used by device-web see DWI-2252

        return $context;
    }

    private function setIntegrityCheckForAsset(Asset $asset, bool $enabled): array
    {
        $this->logger->setAssetContext($asset->getKeyName());

        $localSettings = $asset->getLocal();
        $localSettings->setIntegrityCheckEnabled($enabled);
        $this->assetService->save($asset);

        $context = [
            'keyName' => $asset->getKeyName(),
            'name' => $asset->getName(),
            'enabled' => $enabled
        ];

        $this->logger->info('INT0001 Integrity check changed', $context); // log code is used by device-web see DWI-2252

        return $context;
    }
}
