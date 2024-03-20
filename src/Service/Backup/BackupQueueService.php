<?php

namespace Datto\Service\Backup;

use Datto\Asset\Asset;
use Datto\Backup\BackupManagerFactory;
use Datto\Backup\BackupRequest;
use Datto\Backup\SnapshotStatusService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Handles queuing backups for assets.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class BackupQueueService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DeviceConfig $deviceConfig;
    private DateTimeService $dateTimeService;
    private FeatureService $featureService;
    private AgentConfigFactory $agentConfigFactory;
    private BackupManagerFactory $backupManagerFactory;
    private SnapshotStatusService $snapshotStatusService;
    private Collector $collector;

    public function __construct(
        DeviceConfig $deviceConfig,
        DateTimeService $dateTimeService,
        FeatureService $featureService,
        AgentConfigFactory $agentConfigFactory,
        BackupManagerFactory $backupManagerFactory,
        SnapshotStatusService $snapshotStatusService,
        Collector $collector
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->dateTimeService = $dateTimeService;
        $this->featureService = $featureService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->snapshotStatusService = $snapshotStatusService;
        $this->collector = $collector;
    }

    /**
     * Queue a backup if one is needed for each asset based on its backup schedule and interval.
     */
    public function queueBackupsForAssets(array $assets, bool $forceQueue, array $assetsMetadata = []): void
    {
        $backupOffset = $this->deviceConfig->get('backupOffset', 0);
        $backupOffset = intval($backupOffset) * 60;

        $now = $this->dateTimeService->getTime() + $backupOffset;
        $dayOfWeek = $this->dateTimeService->getDayOfWeek($now);
        $hourOfWeek = $this->dateTimeService->getHourOfWeek($now);
        $hourOfDay = $hourOfWeek % DateTimeService::HOURS_PER_DAY;
        $minuteOfWeek = $this->dateTimeService->getMinuteOfWeek($now);

        foreach ($assets as $asset) {
            if (!$this->featureService->isSupported(FeatureService::FEATURE_ASSET_BACKUPS, null, $asset) ||
                $asset->getLocal()->isPaused()
            ) {
                // Do not queue backups for paused assets and assets that do not support backups
                continue;
            }

            try {
                $this->logger->setAssetContext($asset->getKeyName());
                $backupInterval = $asset->getLocal()->getInterval();
                $backupSchedule = $asset->getLocal()->getSchedule()->getSchedule();
                $shouldBackupThisHour = $backupSchedule[$dayOfWeek][$hourOfDay];
                $shouldBackupThisMinute = $backupInterval != 0 && $minuteOfWeek % $backupInterval === 0;
                if (($shouldBackupThisHour && $shouldBackupThisMinute) || $forceQueue) {
                    $this->queueAssetBackup($asset, $assetsMetadata[$asset->getKeyName()] ?? [], $forceQueue);
                }
            } catch (Throwable $throwable) {
                $this->logger->error('BQS0001 Error queueing backup', ['exception' => $throwable]);
                $this->collector->increment(Metrics::ASSET_BACKUP_SCHEDULE_FAILED, [
                    'forced' => $forceQueue,
                    'reason' => 'error'
                ]);
                $this->snapshotStatusService->updateSnapshotStatus(
                    $asset->getKeyName(),
                    SnapshotStatusService::STATE_SNAPSHOT_QUEUE_FAILED
                );
            }
        }
    }

    /**
     * Clear a queued backup for the given asset, if a backup was queued.
     *
     * @param string $assetKeyName
     * @return bool True if the flag was cleared, false if the clear did not succeed
     */
    public function clearQueuedBackup(string $assetKeyName): bool
    {
        $agentConfig = $this->agentConfigFactory->create($assetKeyName);
        if ($agentConfig->has(BackupRequest::NEEDS_BACKUP_FLAG)) {
            $cleared = $agentConfig->clear(BackupRequest::NEEDS_BACKUP_FLAG);
            if ($cleared) {
                $this->collector->increment(Metrics::ASSET_BACKUP_REMOVED_FROM_SCHEDULE);
            }
            return $cleared;
        }

        return true;
    }

    /**
     * Queue up a backup for the given asset.
     */
    private function queueAssetBackup(Asset $asset, array $metadata, bool $forced): void
    {
        $this->logger->setAssetContext($asset->getKeyName());

        $backupManager = $this->backupManagerFactory->create($asset);
        $agentConfig = $this->agentConfigFactory->create($asset->getKeyName());

        // Increment the queued backups metric for every attempt to queue
        $this->collector->increment(Metrics::ASSET_BACKUP_SCHEDULED, ['forced' => $forced]);

        if ($backupManager->isRunning()) {
            $this->logger->info('BQS0011 Backup requested, but a backup is currently in progress', ['forced' => $forced]);
            $this->collector->increment(Metrics::ASSET_BACKUP_SCHEDULE_FAILED, [
                'forced' => $forced,
                'reason' => 'in-progress'
            ]);
        } elseif ($agentConfig->has(BackupRequest::NEEDS_BACKUP_FLAG)) {
            $this->logger->info('BQS0012 Backup requested, but a backup is already queued', ['forced' => $forced]);
            $this->collector->increment(Metrics::ASSET_BACKUP_SCHEDULE_FAILED, [
                'forced' => $forced,
                'reason' => 'already-queued'
            ]);
        } else {
            $this->logger->info('BQS0010 Backup successfully queued', ['forced' => $forced]);
            $backupRequest = new BackupRequest($this->dateTimeService->getTime(), $metadata);
            $agentConfig->saveRecord($backupRequest);
            $this->snapshotStatusService->updateSnapshotStatus(
                $asset->getKeyName(),
                SnapshotStatusService::STATE_SNAPSHOT_QUEUED
            );
        }
    }
}
