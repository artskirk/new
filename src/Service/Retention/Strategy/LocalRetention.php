<?php

namespace Datto\Service\Retention\Strategy;

use Datto\Asset\Asset;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\RecoveryPoint\LocalSnapshotService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfo;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Asset\Retention;
use Datto\Feature\FeatureService;
use Datto\Service\Retention\AbstractRetentionStrategy;
use Datto\Service\Retention\Exception\RetentionCannotRunException;
use Datto\Service\Retention\RetentionService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Implements the strategy specific to local retention.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class LocalRetention extends AbstractRetentionStrategy
{
    private LocalSnapshotService $localSnapshotService;

    public function __construct(
        Asset $asset,
        FeatureService $featureService,
        RecoveryPointInfoService $recoveryPointService,
        LoggerInterface $logger,
        LocalSnapshotService $localSnapshotService
    ) {
        parent::__construct($asset, $featureService, $recoveryPointService, $logger);

        $this->localSnapshotService = $localSnapshotService;
    }

    public function getLockFilePath(): string
    {
        return sprintf(
            '%s/%s.localRetentionRunning',
            RetentionService::RETENTION_LOCK_FILE_LOCATION,
            $this->asset->getKeyName()
        );
    }

    /**
     * The ERE pattern to match local retention process.
     *
     * The regex matches as long as there's '--local' switch with either
     * matching asset key or '--all' switch, in any position within the command.
     * That is, it will match:
     *
     * asset:retention --local <keyName> --offsite
     * asset:retention --local --offsite --all
     * asset:retention --local --offsite <keyName>
     * asset:retention --offsite --local <keyName>
     * asset:retention --local --all --cron
     * asset:retention --all --local
     * asset:retention <keyName> --local
     *
     * but won't match:
     *
     * asset:retention <someOtherKey> --local
     * asset:retention --offsite <keyName>
     * asset:retention --all --offsite
     * etc
     *
     * @return string
     */
    public function getProcessNamePattern(): string
    {
        return sprintf(
            'asset:retention\s+(.*(%1$s){1}.*--local.*|.*--local.*(%1$s){1}.*)',
            str_replace('.', '\.', $this->asset->getKeyName()) . '|--all' // match key name or --all
        );
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function getDescription(): string
    {
        return 'local';
    }

    public function isSupported(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_LOCAL_RETENTION);
    }

    public function isArchiveRemovalSupported(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_ASSET_ARCHIVAL_RETENTION_LOCAL);
    }

    public function isDisabledByAdmin(): bool
    {
        return false; // always enabled for local retention
    }

    public function isPaused(): bool
    {
        // if an asset is migrating we don't want to do anything that will mess with our snapshot list
        if ($this->asset->getLocal()->isMigrationInProgress()) {
            return true;
        }

        return false;
    }

    public function getSettings(): Retention
    {
        return $this->asset->getLocal()->getRetention();
    }

    public function getRecoveryPoints(): array
    {
        $preventDeletingCritical = !$this->featureService->isSupported(
            FeatureService::FEATURE_ASSET_ARCHIVAL_RETENTION_LOCAL_DELETE_CRITICAL
        );

        $allPoints = $this->recoveryPointService->getAll($this->asset);
        $localPoints = [];

        /** @var RecoveryPointInfo $point */
        foreach ($allPoints as $point) {
            if (!$point->existsLocally()) {
                continue;
            }

            if ($preventDeletingCritical && $point->isCritical()) {
                continue;
            }

            if ($point->hasLocalRestore()) {
                $this->logger->info(
                    'RET0480 Recovery point is used in a local restore ' .
                    'and is not subject to retention at this time.',
                    ['recoveryPoint' => $point->getEpoch()]
                );

                continue;
            }

            $localPoints[] = $point->getEpoch();
        }

        $this->totalPoints = count($localPoints);

        return $localPoints;
    }

    public function deleteRecoveryPoints(array $pointsToDelete, bool $isNightly)
    {
        try {
            $this->logger->info(
                'RET0491 Deleting local points in need of retention',
                ['pointsToDelete' => count($pointsToDelete), 'totalPoints' => $this->totalPoints]
            );

            $this->localSnapshotService->destroy(
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
}
