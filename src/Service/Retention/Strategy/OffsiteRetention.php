<?php

namespace Datto\Service\Retention\Strategy;

use Datto\Asset\Asset;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\RecoveryPoint\OffsiteSnapshotService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfo;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\Retention;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\LocalConfig;
use Datto\Feature\FeatureService;
use Datto\Service\Retention\AbstractRetentionStrategy;
use Datto\Service\Retention\Exception\RetentionCannotRunException;
use Datto\Service\Retention\RetentionService;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Implements strategy specific to offsite retention.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class OffsiteRetention extends AbstractRetentionStrategy
{
    private LocalConfig $localConfig;
    private SpeedSyncMaintenanceService $speedSyncMaintenanceService;
    private OffsiteSnapshotService $offsiteSnapshotService;

    public function __construct(
        Asset $asset,
        FeatureService $featureService,
        RecoveryPointInfoService $recoveryPointService,
        LoggerInterface $logger,
        LocalConfig $localConfig,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        OffsiteSnapshotService $offsiteSnapshotService
    ) {
        parent::__construct($asset, $featureService, $recoveryPointService, $logger);

        $this->localConfig = $localConfig;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->offsiteSnapshotService = $offsiteSnapshotService;
    }

    public function getLockFilePath(): string
    {
        return sprintf(
            '%s/%s.offsiteRetentionRunning',
            RetentionService::RETENTION_LOCK_FILE_LOCATION,
            $this->asset->getKeyName()
        );
    }

    /**
     * The ERE pattern to match offsite retention process.
     *
     * The regex matches as long as there's '--offsite' switch with either
     * matching asset key or '--all' switch, in any position within the command.
     * That is, it will match:
     *
     * asset:retention --offsite <keyName> --local
     * asset:retention --offsite --local --all
     * asset:retention --offsite --local <keyName>
     * asset:retention --local --offsite <keyName>
     * asset:retention --offsite --all --cron
     * asset:retention --all --offsite
     * asset:retention <keyName> --offsite
     *
     * but won't match:
     *
     * asset:retention <someOtherKey> --offsite
     * asset:retention --local <keyName>
     * asset:retention --all --local
     * etc
     *
     * @return string
     */
    public function getProcessNamePattern(): string
    {
        return sprintf(
            'asset:retention\s+(.*(%1$s){1}.*--offsite.*|.*--offsite.*(%1$s){1}.*)',
            str_replace('.', '\.', $this->asset->getKeyName()) . '|--all' // match key name or --all
        );
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getDescription(): string
    {
        return 'offsite';
    }

    public function isSupported(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_OFFSITE_RETENTION);
    }

    public function isArchiveRemovalSupported(): bool
    {
        return false; // never delete offsite archived agent recovery points
    }

    public function isDisabledByAdmin(): bool
    {
        return (bool) $this->localConfig->legacyConfigGet('disabled');
    }

    public function isPaused(): bool
    {
        $isDeviceSpeedSyncPaused = false;
        $isAssetSpeedSyncPaused = false;

        try {
            $isDeviceSpeedSyncPaused = $this->speedSyncMaintenanceService->isDevicePaused();
        } catch (Throwable $ex) {
            throw new RetentionCannotRunException(
                sprintf(
                    'RET0473 Could not retrieve device speedsync options: %s',
                    $ex->getMessage()
                ),
                LogLevel::WARNING
            );
        }

        try {
            $isAssetSpeedSyncPaused = $this->speedSyncMaintenanceService->isAssetPaused($this->asset->getKeyName());
        } catch (Throwable $ex) {
            throw new RetentionCannotRunException(
                sprintf(
                    'RET0473 Could not retrieve asset speedsync options: %s',
                    $ex->getMessage()
                ),
                LogLevel::WARNING
            );
        }

        return $isDeviceSpeedSyncPaused || $isAssetSpeedSyncPaused;
    }

    public function getSettings(): Retention
    {
        return $this->asset->getOffsite()->getRetention();
    }

    public function getRecoveryPoints(): array
    {
        $allPoints = $this->recoveryPointService->getAll($this->asset);
        $offsitePoints = [];

        /** @var RecoveryPointInfo $point */
        foreach ($allPoints as $point) {
            if (!$point->existsOffsite() || $point->isCritical()) {
                $this->logger->debug('RET0501 Skipping point for offsite retention', [
                    'point' => $point->getEpoch(),
                    'existsOffsite' => $point->existsOffsite(),
                    'isCritical' => $point->isCritical()
                ]);
                continue;
            }

            if ($point->hasOffsiteRestore()) {
                $this->logger->info('RET0481 Skipping point with offsite restore', ['point' => $point->getEpoch()]);
                continue;
            }

            $offsitePoints[] = $point->getEpoch();
        }

        $this->totalPoints = count($offsitePoints);

        return $offsitePoints;
    }

    public function deleteRecoveryPoints(array $pointsToDelete, bool $isNightly)
    {
        try {
            $this->logger->info('RET0490 Deleting offsite points in need of retention', [
                'toDelete' => count($pointsToDelete),
                'total' => $this->totalPoints
            ]);

            $pointsToDelete = $this->applyRetentionLimits($isNightly, $pointsToDelete);

            $this->offsiteSnapshotService->destroy(
                $this->asset->getKeyName(),
                $pointsToDelete,
                DestroySnapshotReason::RETENTION()
            );
        } catch (Throwable $ex) {
            throw new RetentionCannotRunException(sprintf(
                'RET1951 Retention failed: %s',
                $ex->getMessage()
            ));
        }
    }

    private function applyRetentionLimits(
        bool $isNightly,
        array $pointsToDelete
    ): array {
        $limit = $this->asset->getOffsite()->getOnDemandRetentionLimit();

        if ($isNightly) {
            $limit = $this->asset->getOffsite()->getNightlyRetentionLimit();
        }

        if (count($pointsToDelete) <= $limit) {
            return $pointsToDelete;
        }

        $this->logger->debug('RET0942 Too many offsite points to delete; removing oldest', [
            'toDelete' => count($pointsToDelete),
            'limit' => $limit
        ]);

        sort($pointsToDelete);

        return array_slice($pointsToDelete, 0, $limit);
    }
}
