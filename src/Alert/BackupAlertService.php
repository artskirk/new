<?php

namespace Datto\Alert;

use Datto\AppKernel;
use Datto\App\Controller\Api\V1\Device\Settings;
use Datto\Asset\Agent\Agent;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Config\AgentConfig;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerFactory;
use Datto\System\MaintenanceModeService;
use Datto\Core\Asset\Configuration\WeeklySchedule;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles checking for outdated backups and triggering alerts in the case that backups are out of date.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class BackupAlertService
{
    private AlertManager $alertManager;
    private AssetService $assetService;
    private DateTimeService $dateTimeService;
    private DeviceConfig $deviceConfig;
    private AgentConfigFactory $agentConfigFactory;
    private AgentStateFactory $agentStateFactory;
    private MaintenanceModeService $maintenanceModeService;
    private Filesystem $filesystem;
    private DeviceLoggerInterface $deviceLogger;

    public function __construct(
        AlertManager $alertManager,
        AssetService $assetService,
        DateTimeService $dateTimeService,
        DeviceConfig $deviceConfig,
        AgentConfigFactory $agentConfigFactory,
        AgentStateFactory $agentStateFactory,
        MaintenanceModeService $maintenanceModeService,
        Filesystem $filesystem,
        DeviceLoggerInterface $deviceLogger
    ) {
        $this->alertManager = $alertManager;
        $this->assetService = $assetService;
        $this->dateTimeService = $dateTimeService;
        $this->deviceConfig = $deviceConfig;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->filesystem = $filesystem;
        $this->deviceLogger = $deviceLogger;
    }

    /**
     * Check all system assets for outdated backups, and log an alert if such is the case for a given asset.
     *
     * @param DeviceLoggerInterface|null $logger
     */
    public function checkForOutdatedBackups(DeviceLoggerInterface $logger = null): void
    {
        $this->deviceLogger->debug('BAS0001 Checking for potentially outdated backups');
        foreach ($this->assetService->getAllLocal() as $asset) {
            $assetKey = $asset->getKeyName();
            $agentConfig = $this->agentConfigFactory->create($assetKey);
            $agentState = $this->agentStateFactory->create($assetKey);

            $lastBackupErrorTime = (int)$agentState->get('backupError');
            $timeSinceLastError = $this->dateTimeService->getTime() - $lastBackupErrorTime;
            $canSend24HourBackupAlert = $timeSinceLastError > DateTimeService::SECONDS_PER_DAY;
            $setBackupAlert = !$asset->getLocal()->isPaused()
                && !$asset->getLocal()->isArchived()
                // TODO: LegacyLastErrorSerializer for this is not actually included in any of the agent serializers,
                // despite the value being present in the asset model; only shares unserialize this value
                && !$agentConfig->has('lastError')
                && $this->hasOutdatedBackup($asset)
                && $canSend24HourBackupAlert;
            if ($setBackupAlert) {
                $logger = $logger ?: LoggerFactory::getAssetLogger($assetKey);
                $alertMessage = $this->getAlertMessage($agentConfig);
                $logger->alert($alertMessage);
            } else {
                $this->alertManager->clearAlerts($asset->getKeyName(), ['BKP4000', 'BKP4010', 'BKP4020', 'BKP4030']);
            }
        }
        $this->deviceLogger->debug('BAS0002 Finished checking for outdated backups');
    }

    /**
     * Checks if the asset hasn't taken a backup on schedule.
     *
     * @param Asset $asset
     * @return bool
     */
    private function hasOutdatedBackup(Asset $asset): bool
    {
        $currentTime = $this->dateTimeService->getTime();
        $backupOffset = $this->deviceConfig->get('backupOffset', 0);
        $lastBackupTime = $this->getLastBackupOrAssetCreationTime($asset);
        // I'm not sure why we add the offset to the last backup time as opposed to the next scheduled backup time;
        // this is a refactor of legacy code and was not meant to change existing behavior. This could potentially
        // be re-examined. However, changing this will break some of the unit tests that were ported from the legacy
        // integration tests.
        $lastBackupTime += (int)$backupOffset * DateTimeService::SECONDS_PER_MINUTE;
        $backupSchedule = $asset->getLocal()->getSchedule();

        $backupMoreThanOneDayOld = ($currentTime - $lastBackupTime) >= DateTimeService::SECONDS_PER_DAY;

        if ($backupMoreThanOneDayOld && !$backupSchedule->isEmpty()) {
            $nextScheduledBackup = $this->findScheduledTimeOfBackupAfterLast($backupSchedule, $lastBackupTime);
            $nextScheduledBackupTimeHasPassed = $nextScheduledBackup < $currentTime;

            // The next scheduled backup will always be at least 1 hour ahead of the last backup time, so we
            // need to account for that. Hence, 23 hours used here as opposed to 24. This is a refactor of
            // legacy code, and does not currently account for backup frequencies of greater than once per hour. That
            // level of precision isn't really necessary for this particular alert anyway.
            $twentyThreeHours = DateTimeService::SECONDS_PER_DAY - DateTimeService::SECONDS_PER_HOUR;
            $twentyThreeHoursSinceScheduledBackup = ($currentTime - $nextScheduledBackup) >= $twentyThreeHours;

            return $nextScheduledBackupTimeHasPassed && $twentyThreeHoursSinceScheduledBackup;
        }
        return false;
    }

    /**
     * Get the alert message to use for logging an outdated backup for an asset (given their AgentConfig instance)
     *
     * @param AgentConfig $config
     * @return string
     */
    private function getAlertMessage(AgentConfig $config): string
    {
        if ($this->maintenanceModeService->isEnabled()) {
            return "BKP4020 A backup hasn't been taken in over 24 hours, this is likely due to an active RoundTrip or ongoing troubleshooting by Technical Support.";
        }

        $maximumConcurrentBackups = (int)$this->deviceConfig->get('maxBackups', Settings::DEFAULT_CONCURRENT_BACKUPS);
        if ($maximumConcurrentBackups === 0) {
            return "BKP4030 A backup hasn't been taken in over 24 hours, this is likely due to your device being set to have zero concurrent backups.";
        }

        $needsBackup = $config->has('needsBackup');
        $numberOfAgentsNeedingBackup = count($this->filesystem->glob(Agent::KEYBASE . '*.needsBackup') ?? []);
        if ($needsBackup && $numberOfAgentsNeedingBackup > $maximumConcurrentBackups) {
            return "BKP4010 A backup hasn't been taken in over 24 hours, this is likely due to reaching the maximum concurrent backup limit set for this device.";
        }

        return "BKP4000 A backup hasn't been taken in over 24 hours";
    }

    /**
     * Gets the time of the last backup; If no backups exist, gets the date that the asset was added.
     *
     * @param Asset $asset
     * @return int
     */
    private function getLastBackupOrAssetCreationTime(Asset $asset): int
    {
        $localPoints = $asset->getLocal()->getRecoveryPoints();
        $lastPoint = $localPoints->getLast();

        if ($lastPoint) {
            return $lastPoint->getEpoch();
        }
        return $asset->getDateAdded();
    }

    /**
     * Find the scheduled time of the backup that should take (or should have taken) place after the
     * given last backup time.
     *
     * @param WeeklySchedule $schedule
     * @param int $lastBackupTime
     * @return int
     */
    private function findScheduledTimeOfBackupAfterLast(WeeklySchedule $schedule, int $lastBackupTime): int
    {
        $nextBackup = $lastBackupTime + DateTimeService::SECONDS_PER_HOUR;
        for ($i = 0; $i <= DateTimeService::HOURS_PER_WEEK; $i++) {
            if ($schedule->checkWeekHour($this->dateTimeService->getHourOfWeek($nextBackup))) {
                return $nextBackup;
            }
            $nextBackup += DateTimeService::SECONDS_PER_HOUR;
        }
        return 0;
    }
}
