<?php

namespace Datto\App\Controller\Api\V1\Device\Asset;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\Offsite\OffsiteSettingsService;
use Datto\Asset\OffsiteSettings;
use Datto\Asset\Serializer\WeeklyScheduleSerializer;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Resource\DateTimeService;
use Exception;
use Throwable;

/**
 * This class contains the API endpoints for updating
 * the offsite settings for assets.
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
class Offsite extends AbstractAssetEndpoint
{
    private const INVALID_SCHEDULE = 1;

    protected WeeklyScheduleSerializer $scheduleSerializer;

    private OffsiteSettingsService $offsiteSettingsService;

    private DateTimeService $dateTimeService;

    private EncryptionService $encryptionService;

    public function __construct(
        OffsiteSettingsService $offsiteSettingsService,
        AssetService $assetService,
        WeeklyScheduleSerializer $scheduleSerializer,
        DateTimeService $dateTimeService,
        EncryptionService $encryptionService
    ) {
        parent::__construct($assetService);

        $this->offsiteSettingsService = $offsiteSettingsService;
        $this->scheduleSerializer = $scheduleSerializer;
        $this->dateTimeService = $dateTimeService;
        $this->encryptionService = $encryptionService;
    }

    /**
     * Retrieve the offsite retention values for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~")
     * })
     *
     * @param string $name Name of target asset to retrieve retention for
     * @return array
     */
    public function getRetention(string $name): array
    {
        $asset = $this->assetService->get($name);
        $retention = $asset->getOffsite()->getRetention();

        return [
            'name' => $asset->getName(),
            'retention' => [
                'daily' => $retention->getDaily(),
                'weekly' => $retention->getWeekly(),
                'monthly' => $retention->getMonthly(),
                'keep' => $retention->getMaximum()
            ]
        ];
    }

    /**
     * Retrieve the offsite retention values for all assets of a given type.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @param string|null $type
     * @return array
     */
    public function getRetentionAll(string $type = null): array
    {
        $retentionArrays = [];
        $assets = $this->assetService->getAllActiveLocal($type);
        foreach ($assets as $asset) {
            $retentionArrays[] = $this->getRetention($asset->getKeyName());
        }

        return $retentionArrays;
    }

    /**
     * Set the offsite retention values for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "daily" = @Symfony\Component\Validator\Constraints\Choice(choices = {24, 48, 72, 96, 120, 144, 168, 336, 504, 744, 240000}),
     *   "weekly" = @Symfony\Component\Validator\Constraints\Range(min = 48, max = 240000),
     *   "monthly" = @Symfony\Component\Validator\Constraints\Range(min = 731, max = 240000),
     *   "keep" = @Symfony\Component\Validator\Constraints\Choice(choices = {168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064,
     *       43830, 52596, 61362, 240000}),
     * })
     * @param string $name Name of the asset
     * @param int $daily time to retain, in hours
     * @param int $weekly time to retain, in hours
     * @param int $monthly time to retain, in hours
     * @param int $keep maximum time to retain
     * @return array
     */
    public function setRetention(string $name, int $daily, int $weekly, int $monthly, int $keep): array
    {
        $asset = $this->assetService->get($name);

        if ($this->encryptionService->isAgentSealed($name)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        if (!$this->hasPermissionToChangeRetention($asset)) {
            throw new Exception('User does not have permission to change retention');
        }

        $this->offsiteSettingsService->setRetention($name, $daily, $weekly, $monthly, $keep);

        return $this->getRetention($name);
    }

    /**
     * Set the offsite retention values for all assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_RETENTION")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RETENTION_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "daily" = @Symfony\Component\Validator\Constraints\Choice(choices = {24, 48, 72, 96, 120, 144, 168, 336, 504, 744, 240000}),
     *   "weekly" = @Symfony\Component\Validator\Constraints\Range(min = 168, max = 240000),
     *   "monthly" = @Symfony\Component\Validator\Constraints\Range(min = 731, max = 240000),
     *   "keep" = @Symfony\Component\Validator\Constraints\Choice(choices = {168, 336, 744, 1488, 2232, 2976, 4464, 6696, 8760, 17520, 26280, 35064,
     *       43830, 52596, 61362, 240000}),
     * })
     * @param int $daily time to retain, in hours
     * @param int $weekly time to retain, in hours
     * @param int $monthly time to retain, in hours
     * @param int $keep maximum time to retain
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array
     */
    public function setRetentionAll(int $daily, int $weekly, int $monthly, int $keep, string $type = null): array
    {
        $this->offsiteSettingsService->setRetentionAll($daily, $weekly, $monthly, $keep, $type);

        return $this->getRetentionAll($type);
    }

    /**
     * Gets the offsite backup schedule for an asset defined by $name.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_READ")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Symfony\Component\Validator\Constraints\Regex(pattern="~^[A-Za-z\d\-\_\.]+$~")
     * })
     * @param string $name Name of the asset to get schedule for
     * @return array An array containing asset name and the current schedule
     */
    public function getSchedule(string $name): array
    {
        $asset = $this->assetService->get($name);

        return array(
            'name' => $name,
            'schedule' => $this->scheduleSerializer->serialize($asset->getOffsite()->getSchedule())
        );
    }

    /**
     * Set the frequency of replicating an asset offsite and its priority.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetName" = @Datto\App\Security\Constraints\AssetExists(),
     *   "interval" = @Symfony\Component\Validator\Constraints\Choice(choices = {"always", "never", "custom", "3600",
     *       "7200", "10800", "14400", "21600", "43200", "86400", "172800", "259200", "345600", "432000", "518400",
     *       "604800", "1209600", "1814400", "2419200", "15768000"}),
     *   "priority" = @Symfony\Component\Validator\Constraints\Choice(choices = {"low", "normal", "high"})
     * })
     *
     * @param string $interval The offsite replication interval
     * @param string $priority The offsite replication priority
     * @param string $assetName Name of the asset
     * @return array an array containing asset name, interval, priority, and backup count
     */
    public function setIntervalAndPriority(string $assetName, string $interval, string $priority): array
    {
        if ($this->encryptionService->isAgentSealed($assetName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($assetName);
        $this->validateAsset($asset);

        return $this->setIntervalAndPriorityForAsset($asset, $interval, $priority);
    }

    /**
     * Set the interval and priority for all assets.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetName" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[A-Za-z\d\-\_\.]+$~"),
     *   "interval" = @Symfony\Component\Validator\Constraints\Choice(choices = {"always", "never", "custom", "3600",
     *       "7200", "10800", "14400", "21600", "43200", "86400", "172800", "259200", "345600", "432000", "518400",
     *       "604800", "1209600", "1814400", "2419200", "15768000"}),
     *   "priority" = @Symfony\Component\Validator\Constraints\Choice(choices = {"low", "normal", "high"})
     * })
     *
     * @param string $interval The offsite replication interval
     * @param string $priority The offsite replication priority
     * @param string|null $type Optional parameter to limit this to a specific type of asset
     * @return array[] an array containing asset name, interval, priority, and backup count for each asset
     */
    public function setIntervalAndPriorityAll(string $interval, string $priority, ?string $type = null): array
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = [];
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("IAP0001 Interval and priority check not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            $status[] = $this->setIntervalAndPriorityForAsset($asset, $interval, $priority);
        }

        return $status;
    }

    /**
     * Set the custom schedule and priority for an asset.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "assetName" = @Datto\App\Security\Constraints\AssetExists(),
     *   "priority" = @Symfony\Component\Validator\Constraints\Choice(choices = {"low", "normal", "high"})
     * })
     * @param string $assetName The asset's name
     * @param array $newSchedule the new schedule to set (7x24 table with bool or 1/0)
     * @param string $priority The new priority to set
     * @return array an array containing asset name, schedule, and backup count
     */
    public function setCustomScheduleAndPriority(string $assetName, array $newSchedule, string $priority): array
    {
        if ($this->encryptionService->isAgentSealed($assetName)) {
            throw new Exception("Settings cannot be changed on a sealed asset");
        }

        $asset = $this->assetService->get($assetName);
        $this->validateAsset($asset);

        return $this->setCustomScheduleAndPriorityForAsset($asset, $newSchedule, $priority);
    }

    /**
     * Set the custom offsite schedule and priority for this all assets on this device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ASSETS")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ASSET_WRITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *  "priority" = @Symfony\Component\Validator\Constraints\Choice(choices = {"low", "normal", "high"})
     * })
     * @param array $newSchedule The new schedule to set (7x24 table with bool or 1/0)
     * @param string $priority The new priority to set
     * @param string|null $type Optional parameter to limit applying this setting to a specific asset type
     * @return array[] an array containing asset name, schedule, and backup count (or error information in the case of
     * an incompatible local schedule) for each agent.
     */
    public function setCustomScheduleAndPriorityAll(array $newSchedule, string $priority, ?string $type = null): array
    {
        $assets = $this->assetService->getAllActiveLocal($type);

        $status = [];
        foreach ($assets as $asset) {
            if ($this->encryptionService->isAgentSealed($asset->getKeyName())) {
                $this->logger->warning("SAP0001 Schedule and priority not changed for sealed asset", ["name" => $asset->getName()]);
                continue;
            }

            try {
                $status[] = $this->setCustomScheduleAndPriorityForAsset($asset, $newSchedule, $priority);
            } catch (Throwable $e) {
                $status[] = [
                    'name' => $asset->getName(),
                    'keyName' => $asset->getKeyName(),
                    'error' => [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage()
                    ]
                ];
            }
        }
        return $status;
    }

    /**
     * Check if given asset supports changing of offsite settings.
     *
     * @param Asset $asset
     */
    private function validateAsset(Asset $asset): void
    {
        if ($asset->getOriginDevice()->isReplicated()) {
            throw new Exception('Changing of offsite setting for replicated agents is not allowed.');
        }
    }

    private function hasPermissionToChangeRetention(Asset $asset): bool
    {
        $agentAddedRecently = $asset->getDateAdded() + DateTimeService::SECONDS_PER_DAY > $this->dateTimeService->getTime();

        // Permission to create an agent is less restricted than permission to change retention. However, agent pairing
        // needs to be able to change retention so we allow it if the agent isn't very old yet.
        return $this->isGranted("PERMISSION_RETENTION_WRITE") ||
            ($this->isGranted("PERMISSION_AGENT_CREATE") && $agentAddedRecently);
    }

    private function setIntervalAndPriorityForAsset(Asset $asset, string $interval, string $priority): array
    {
        $asset->getOffsite()->setReplication($interval);
        $asset->getOffsite()->setPriority($priority);
        $this->assetService->save($asset);

        $backupCount = $asset->getOffsite()->calculateWeeklyOffsiteCount(
            $asset->getLocal()->getInterval(),
            $asset->getLocal()->getSchedule()
        );

        $this->logger->setAssetContext($asset->getKeyName());
        $this->logger->info('SCH0003 Offsite backup interval changed', ['interval' => $interval, 'priority' => $priority]); // log code is used by device-web see DWI-2252

        return [
            'name' => $asset->getName(),
            'keyName' => $asset->getKeyName(),
            'interval' => $interval,
            'priority' => $priority,
            'backupCount' => $backupCount
        ];
    }

    private function setCustomScheduleAndPriorityForAsset(Asset $asset, array $newSchedule, string $priority): array
    {
        $weeklySchedule = new WeeklySchedule();
        $weeklySchedule->setSchedule($newSchedule);

        if ($weeklySchedule->isValidWithFilter($asset->getLocal()->getSchedule())) {
            $asset->getOffsite()->setSchedule($weeklySchedule);
            $asset->getOffsite()->setReplication(OffsiteSettings::REPLICATION_CUSTOM);
            $asset->getOffsite()->setPriority($priority);
            $this->assetService->save($asset);
        } else {
            throw new Exception(
                'This offsite schedule is incompatible with the asset\'s local schedule',
                self::INVALID_SCHEDULE
            );
        }

        $backupCount = $asset->getOffsite()->calculateWeeklyOffsiteCount(
            $asset->getLocal()->getInterval(),
            $asset->getLocal()->getSchedule()
        );

        $this->logger->setAssetContext($asset->getKeyName());
        $this->logger->info('SCH0002 Offsite backup schedule changed', ['schedule' => $newSchedule, 'priority' => $priority]); // log code is used by device-web see DWI-2252

        return [
            'name' => $asset->getName(),
            'keyName' => $asset->getKeyName(),
            'schedule' => $this->scheduleSerializer->serialize($asset->getOffsite()->getSchedule()),
            'backupCount' => $backupCount,
            'interval' => OffsiteSettings::REPLICATION_CUSTOM
        ];
    }
}
