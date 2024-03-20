<?php

namespace Datto\Service\Backup;

use Datto\App\Console\Command\Asset\Backup\BackupScheduleRunCommand;
use Datto\App\Console\SnapctlApplication;
use Datto\App\Controller\Api\V1\Device\Settings;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Backup\BackupManagerFactory;
use Datto\Backup\BackupRequest;
use Datto\Backup\SnapshotStatusService;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Config\DeviceState;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Mercury\MercuryFtpService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Reporting\Snapshots;
use Datto\Resource\DateTimeService;
use Datto\System\MaintenanceModeService;
use Datto\Utility\Screen;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Performs scheduled backups for assets if needed.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class BackupScheduleRunService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SCREEN_SESSION_NAME_FORMAT = 'startBackup-%s';
    const SNAPSHOT_ERROR_LOG_FILE = 'snp.log.error';

    /** @var int The minimum amount of time in seconds we will wait until we forcibly restart the MercuryFTP service. */
    private const WAIT_FORCE_RESTART_MERCURY_SEC = 24 * 60 * 60; // 24 hours

    /** @var string[] The hours during which it is more acceptable to miss backups or cause backups to fail. This is
     * between 11pm and 1am.
     */
    private const LOW_IMPACT_HOURS = [
        'start' => 23,
        'end' => 1,
    ];

    private DateTimeService $dateTimeService;
    private FeatureService $featureService;
    private DeviceConfig $deviceConfig;
    private AgentConfigFactory $agentConfigFactory;
    private Snapshots $snapshotLog;
    private MaintenanceModeService $maintenanceModeService;
    private BackupManagerFactory $backupManagerFactory;
    private AssetService $assetService;
    private Screen $screen;
    private SnapshotStatusService $snapshotStatusService;
    private Collector $collector;
    private DeviceState $deviceState;
    private MercuryFtpService $mercuryFtpService;

    public function __construct(
        DateTimeService $dateTimeService,
        FeatureService $featureService,
        DeviceConfig $deviceConfig,
        AgentConfigFactory $agentConfigFactory,
        Snapshots $snapshotLog,
        MaintenanceModeService $maintenanceModeService,
        BackupManagerFactory $backupManagerFactory,
        AssetService $assetService,
        Screen $screen,
        SnapshotStatusService $snapshotStatusService,
        Collector $collector,
        DeviceState $deviceState,
        MercuryFtpService $mercuryFtpService
    ) {
        $this->dateTimeService = $dateTimeService;
        $this->featureService = $featureService;
        $this->deviceConfig = $deviceConfig;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->snapshotLog = $snapshotLog;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->assetService = $assetService;
        $this->screen = $screen;
        $this->snapshotStatusService = $snapshotStatusService;
        $this->collector = $collector;
        $this->deviceState = $deviceState;
        $this->mercuryFtpService = $mercuryFtpService;
    }

    /**
     * Run a scheduled backup if one is needed for the given asset.
     *
     * @param string $assetKeyName
     */
    public function runScheduledBackupForAsset(string $assetKeyName): void
    {
        $asset = $this->assetService->get($assetKeyName);
        $this->logger->setAssetContext($assetKeyName);
        $agentConfig = $this->agentConfigFactory->create($assetKeyName);
        $backupRequest = $this->readBackupRequest($agentConfig);

        $agentConfig->clearRecord($backupRequest);

        // Increment the metric indicating that we have pulled the asset from the queue
        $queuedTime = $this->dateTimeService->getTime() - $backupRequest->getQueuedTime();
        $this->collector->increment(Metrics::ASSET_BACKUP_STARTED);
        $this->collector->timing(Metrics::ASSET_BACKUP_TIME_IN_SCHEDULE, $queuedTime);

        $this->logger->debug('BKP0613 Starting scheduled backup and clearing needsBackup flag', ['queuedTime' => $queuedTime]);

        $startTime = $this->dateTimeService->getTime();
        try {
            $backupManager = $this->backupManagerFactory->create($asset);
            $this->snapshotStatusService->updateSnapshotStatus($asset->getKeyName(), SnapshotStatusService::STATE_SNAPSHOT_STARTED);
            $backupManager->startScheduledBackup($backupRequest->getMetadata());
        } catch (Throwable $throwable) {
            $now = $this->dateTimeService->getTime();
            $intervalInSeconds = $asset->getLocal()->getInterval() * DateTimeService::SECONDS_PER_MINUTE;
            $latestRecoveryPoint = $asset->getLocal()->getRecoveryPoints()->getLast();

            $latestBackupEpoch = is_null($latestRecoveryPoint) ? 0 : $latestRecoveryPoint->getEpoch();
            if ($now > $latestBackupEpoch + $intervalInSeconds) {
                $lastErrorEpoch = $agentConfig->get(self::SNAPSHOT_ERROR_LOG_FILE, $latestBackupEpoch);

                if ($now > $lastErrorEpoch + $intervalInSeconds) {
                    $this->snapshotLog->log($assetKeyName, 'BKP2126', 'Snapshot requested', $startTime);
                    $this->snapshotLog->log($assetKeyName, 'BKP1650', 'Backup failed as Backup job was unable to be assigned', $now);
                }
            }

            throw $throwable;  // Rethrow after logging
        }
    }

    /**
     * Run scheduled backups in separate screens for all assets if necessary.
     */
    public function scheduleBackupsForAllAssets(): void
    {
        if ($this->maintenanceModeService->isEnabled()) {
            $this->logger->info('BSR0010 Maintenance mode is enabled - Skipping scheduler');
            return;
        }

        $currentBackupCount = $this->countActiveScheduledBackups();

        $isCloudDevice = $this->deviceConfig->isCloudDevice();
        $defaultMaxBackups = $isCloudDevice ? Settings::DEFAULT_CONCURRENT_BACKUPS_CLOUD_DEVICE : Settings::DEFAULT_CONCURRENT_BACKUPS;
        $maximumConcurrentBackups = $this->deviceConfig->get('maxBackups', $defaultMaxBackups);

        $possibleNewBackups = $maximumConcurrentBackups - $currentBackupCount;
        $backupPriorities = $this->getBackupPriorityList();

        $this->logger->debug('BSR0012 Backup Schedule Stats', [
            'currentBackupCount' => $currentBackupCount,
            'maxConcurrentBackups' => $maximumConcurrentBackups,
            'possibleNewBackups' => $possibleNewBackups,
            'scheduleLength' => count($backupPriorities)
        ]);

        $this->collector->measure(Metrics::BACKUP_SCHEDULE_QUEUE_LENGTH, count($backupPriorities));

        if ($this->needToRestartMercuryNow()) {
            $this->mercuryFtpService->restart();
            $needToWaitToRestartMercury = false;
        } else {
            $needToWaitToRestartMercury = $this->needToWaitToRestartMercury();
        }

        foreach ($backupPriorities as $assetKeyName => $backupQueuedEpoch) {
            $assetContributesToBackupLimit = $this->isIncludedInConcurrentBackupLimit($assetKeyName);
            if ($possibleNewBackups > 0 || !$assetContributesToBackupLimit) {
                $screenName = sprintf(self::SCREEN_SESSION_NAME_FORMAT, $assetKeyName);
                $this->logger->setAssetContext($assetKeyName);
                if ($this->screen->isScreenRunning($screenName)) {
                    $this->logger->info("BSR0011 Backup is already running in screen - Skipping scheduler", ['screen' => $screenName]);
                } elseif ($needToWaitToRestartMercury && $this->mightUseMercuryTransport($assetKeyName)) {
                    $this->logger->info("BSR0013 Backup is delayed to allow time for MercuryFTP to restart - Skipping scheduler");
                } else {
                    $this->logger->info("BSR0014 Starting scheduled backup in screen", ['screen' => $screenName, 'asset' => $assetKeyName]);
                    $command = [SnapctlApplication::EXECUTABLE_NAME, BackupScheduleRunCommand::getDefaultName(), $assetKeyName];
                    $screenStarted = $this->screen->runInBackground($command, $screenName);
                    if ($screenStarted && $assetContributesToBackupLimit) {
                        --$possibleNewBackups;
                    }
                }
            }
        }
    }

    /**
     * Get a list of assets based on when their backup was queued.
     * The oldest backup request is listed first.
     *
     * @return int[]
     */
    private function getBackupPriorityList(): array
    {
        $backupPriorities = [];

        $assets = $this->assetService->getAllActive();
        foreach ($assets as $asset) {
            $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());
            $backupManager = $this->backupManagerFactory->create($asset);

            $backupsSupported = $this->featureService->isSupported(FeatureService::FEATURE_ASSET_BACKUPS, null, $asset);
            $backupQueued = $agentConfig->has('needsBackup');
            $backupsPaused = $asset->getLocal()->isPaused();
            $backupInProgress = $backupManager->isRunning();

            if ($backupsSupported && $backupQueued && !$backupsPaused && !$backupInProgress) {
                $agentBackupRequest = $this->readBackupRequest($agentConfig);
                $backupQueuedEpoch = $agentBackupRequest->getQueuedTime();
                $backupPriorities[$asset->getKeyName()] = $backupQueuedEpoch;
            }
        }

        asort($backupPriorities);
        return $backupPriorities;
    }

    /**
     * Get the number of actively running scheduled backups.
     *
     * @return int
     */
    private function countActiveScheduledBackups(): int
    {
        $currentBackupCount = 0;
        $partialScreenName = sprintf(self::SCREEN_SESSION_NAME_FORMAT, '');
        $screens = $this->screen->getScreens($partialScreenName);

        foreach ($screens as $screen) {
            $assetKeyName = pathinfo($screen, PATHINFO_FILENAME);
            if ($this->isIncludedInConcurrentBackupLimit($assetKeyName)) {
                ++$currentBackupCount;
            }
        }

        return $currentBackupCount;
    }

    /**
     * Check if the given asset's backups should be limited by the maximum backup limit.
     * Shares other than external NAS shares and Rescue Agents do not contribute to the maximum concurrent backup limit.
     *
     * @param string $assetKeyName
     * @return bool
     */
    private function isIncludedInConcurrentBackupLimit(string $assetKeyName): bool
    {
        $agentConfig = $this->agentConfigFactory->create($assetKeyName);
        if ($agentConfig->isShare()) {
            // Including external NAS share in the count as it can tax system resources since it involves
            // disk/network i/o. For internal shares, there is no disk/network i/o as they are just zfs snapshots.
            $isExternalNasShare = false;
            try {
                $isExternalNasShare = $this->assetService->get($assetKeyName)->isType(AssetType::EXTERNAL_NAS_SHARE);
            } catch (Throwable $throwable) {
                $this->logger->warning('BSR0016 Getting asset failed', ['asset' => $assetKeyName,
                    'error' => $throwable->getMessage()]);
                $isExternalNasShare = false;
            }
            return $isExternalNasShare;
        }

        return !$agentConfig->isRescueAgent();
    }

    /**
     * attempt to load BackupRequest. If for whatever reason needsBackup is not the correct format, fall back
     * to just reading the raw value.
     *
     * @param AgentConfig $agentConfig
     * @return BackupRequest
     */
    private function readBackupRequest(AgentConfig $agentConfig): BackupRequest
    {
        $agentBackupRequest = new BackupRequest();

        try {
            $agentConfig->loadRecord($agentBackupRequest);
        } catch (Throwable $throwable) {
            $this->logger->info('BSR0015 needsBackup file was malformed');
            $backupTime = $agentConfig->get(BackupRequest::NEEDS_BACKUP_FLAG, 0);
            $agentBackupRequest->setQueuedTime($backupTime);
        }

        return $agentBackupRequest;
    }

    /**
     * Returns true if it is necessary to delay backups, so we can restart the MercuryFTP service.
     */
    private function needToWaitToRestartMercury(): bool
    {
        try {
            $mercuryRestartTime = intval($this->deviceState->get(DeviceState::MERCURYFTP_RESTART_REQUESTED, 0));
            if ($mercuryRestartTime <= 0) {
                // No restart is needed.
                return false;
            }

            return $this->isDuringLowImpactHours();
        } catch (Throwable $ex) {
            $this->logger->warning('BSR0020 Error checking if MercuryFTP needs to be restarted', ['exception' => $ex]);
            return false;
        }
    }

    /**
     * Returns true if it is necessary to restart the MercuryFTP service now.
     */
    private function needToRestartMercuryNow(): bool
    {
        try {
            $mercuryRestartTime = intval($this->deviceState->get(DeviceState::MERCURYFTP_RESTART_REQUESTED, 0));
            if ($mercuryRestartTime <= 0) {
                // No restart is needed.
                return false;
            }

            if (count($this->mercuryFtpService->listTargets()) === 0) {
                $this->logger->info('BSR0017 Restarting MercuryFTP because it has no active targets.');
                return true;
            }

            $now = $this->dateTimeService->getTime();
            $isPastForceRestartTime = $now - $mercuryRestartTime > self::WAIT_FORCE_RESTART_MERCURY_SEC;
            if ($isPastForceRestartTime && $this->isDuringLowImpactHours()) {
                // We don't want to go too long without restarting the MercuryFTP service when it needs to be restarted,
                // so we will forcibly restart it now. If a MercuryFTP backup is running, it will fail and the agent
                // will fall back to iSCSI. If a BMR is running, the user may have to retry it.
                $this->logger->info(
                    'BSR0018 Restarting MercuryFTP because we are past the wait time to forcibly restart.',
                    ['waitTimeHours' => self::WAIT_FORCE_RESTART_MERCURY_SEC / 3600]
                );
                return true;
            }

            // We still need to restart Mercury, but we are not yet at the time to forcibly restart it.
            return false;
        } catch (Throwable $ex) {
            $this->logger->warning('BSR0020 Error checking if MercuryFTP needs to be restarted', ['exception' => $ex]);
            return false;
        }
    }

    /**
     * Returns true if the current time is during low impact hours.
     */
    private function isDuringLowImpactHours(): bool
    {
        // Get the current hour in the local timezone.
        $nowHour = intval($this->dateTimeService->getDate('G'));
        // We are using '||' because low impact hours span the end of the day and the beginning of the day.
        return $nowHour >= self::LOW_IMPACT_HOURS['start'] || $nowHour < self::LOW_IMPACT_HOURS['end'];
    }

    /**
     *
     * Returns true if this is an asset that might use MercuryFTP as its backup transport.
     */
    private function mightUseMercuryTransport(string $assetKeyName): bool
    {
        $asset = $this->assetService->get($assetKeyName);
        // We could do more sophisticated checks here, but this is good enough for our current purpose.
        return $asset->isType(AssetType::WINDOWS_AGENT) || $asset->isType(AssetType::LINUX_AGENT);
    }
}
