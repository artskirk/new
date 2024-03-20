<?php

namespace Datto\Asset;

use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\RecoveryPoint\RecoveryPointHistoryRecord;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoSummary;
use Datto\Asset\RecoveryPoint\RecoveryPoints;
use Datto\Cloud\SpeedSync;
use Datto\Cloud\SpeedSyncCache;
use Datto\Cloud\SpeedSyncCacheEntry;
use Datto\Config\AgentConfigFactory;
use Datto\Resource\DateTimeService;
use Datto\ZFS\ZfsService;

/**
 * Service class for analyzing growth rates
 *
 * @author Justin Giacobbi <jgiacobbi@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class AssetSummaryService
{
    const DATE_EPOCH_FORMAT = "U";
    const DATE_KEY_FORMAT = "Y-z";
    const RATE_GROWTH = "-91 days";
    const RATE_CHANGE = "-31 days";
    const MIN_DAYS = 10;
    const BAD_DATA = -1; // Missing or insufficient history

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var ZfsService */
    private $zfsService;

    /** @var SpeedSyncCache|null */
    private $speedsyncCache = null;

    /** @var SpeedSync */
    private $speedsync;

    /**
     * @param AgentConfigFactory $agentConfigFactory
     * @param DateTimeService $dateTimeService
     * @param EncryptionService $encryptionService
     * @param ZfsService $zfsService
     * @param SpeedSync $speedsync
     */
    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DateTimeService $dateTimeService,
        EncryptionService $encryptionService,
        ZfsService $zfsService,
        SpeedSync $speedsync
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->dateTimeService = $dateTimeService;
        $this->encryptionService = $encryptionService;
        $this->zfsService = $zfsService;
        $this->speedsync = $speedsync;
    }

    /**
     * Get summarized information about all recovery-points.
     *
     * @param Asset $asset
     * @return RecoveryPointInfoSummary
     */
    public function getSummary(Asset $asset): RecoveryPointInfoSummary
    {
        $this->readCaches();

        $localUsedSize = (int)$this->getLocalTotalUsedSize($asset);
        $offsiteUsedSize = (int)$this->getOffsiteTotalUsedSize($asset);

        $latestLocalSnapshotEpoch = (int)$this->getLatestSnapshotEpoch($asset->getLocal()->getRecoveryPoints());
        $latestOffsiteSnapshotEpoch = (int)$this->getLatestSnapshotEpoch($asset->getOffsite()->getRecoveryPoints());

        $rateOfChange = $this->getRateOfChange($asset);
        list($growthAbsolute, $growthPercent) = $this->getRateOfGrowth($asset);

        return new RecoveryPointInfoSummary(
            $localUsedSize,
            $offsiteUsedSize,
            $latestLocalSnapshotEpoch,
            $latestOffsiteSnapshotEpoch,
            $rateOfChange,
            $growthAbsolute,
            $growthPercent
        );
    }

    /**
     * Load all caches from disk
     */
    public function readCaches(): void
    {
        if ($this->speedsyncCache === null) {
            $this->speedsyncCache = $this->speedsync->readCache();
        }
    }

    /**
     * Get the rate of growth for an asset, based on 90 days of backups
     *
     * @param Asset $asset
     * @return array
     */
    private function getRateOfGrowth(Asset $asset): array
    {
        $points = $this->getHistory(
            $asset,
            static::RATE_GROWTH,
            RecoveryPointHistoryRecord::TOTAL_USED
        );
        $points = $this->stripOutliers($points);

        $baseSize = $this->getBaseImageSize($asset);

        if (count($points) < 2) {
            return [static::BAD_DATA, static::BAD_DATA];
        } else {
            $daily = [];

            //unset and record first value of array
            ksort($points);
            $previous = reset($points);
            unset($points[key($points)]);

            foreach ($points as $epoch => $used) {
                $day = $this->dateTimeService->format(self::DATE_KEY_FORMAT, $epoch);

                $growth = $used - $previous;
                $previous = $used;
                $daily[$day] = ($daily[$day] ?? 0) + $growth;
            }

            if (count($daily) < static::MIN_DAYS) {
                return [static::BAD_DATA, static::BAD_DATA];
            } else {
                $absolute = array_sum($daily) / count($daily);
                $percent = 100 * $absolute / $baseSize;

                return [floor($absolute), $percent];
            }
        }
    }

    /**
     * Get the daily rate of change for an asset in bytes, based on 30 days of backups
     *
     * @param Asset $asset
     * @return int
     */
    private function getRateOfChange(Asset $asset): int
    {
        $points = $this->getHistory(
            $asset,
            static::RATE_CHANGE,
            RecoveryPointHistoryRecord::TRANSFER
        );
        $points = $this->stripOutliers($points);

        if (count($points) < 1) {
            return static::BAD_DATA;
        } else {
            $daily = [];

            foreach ($points as $epoch => $bytes) {
                $day = $this->dateTimeService->format(self::DATE_KEY_FORMAT, $epoch);
                $daily[$day] = ($daily[$day] ?? 0) + $bytes;
            }

            if (count($daily) < static::MIN_DAYS) {
                return static::BAD_DATA;
            } else {
                return array_sum($daily) / count($daily);
            }
        }
    }

    /**
     * Gets the last 30 days of transfers from the transfer history
     *
     * @param Asset $asset
     * @param string $rateType
     * @param string $column
     * @return array
     */
    private function getHistory(Asset $asset, string $rateType, string $column): array
    {
        $assetKey = $asset->getKeyName();
        $agentConfig = $this->agentConfigFactory->create($assetKey);

        $recoveryPointHistory = new RecoveryPointHistoryRecord();
        $agentConfig->loadRecord($recoveryPointHistory);
        $history = $recoveryPointHistory->getColumn($column);

        $minPossibleDay = $this->dateTimeService->format(
            self::DATE_EPOCH_FORMAT,
            $this->dateTimeService->stringToTime($rateType)
        );
        $maxPossibleDay = $this->dateTimeService->format(self::DATE_EPOCH_FORMAT);

        $out = array_filter(
            $history,
            function ($epoch) use ($minPossibleDay, $maxPossibleDay) {
                return $epoch > $minPossibleDay && $epoch < $maxPossibleDay;
            },
            ARRAY_FILTER_USE_KEY
        );

        return $out;
    }

    /**
     * Remove outliers from an array
     *
     * @param array
     * @return array
     */
    private function stripOutliers(array $values): array
    {
        if (count($values) > 2) {
            $stdDev = $this->stdDev($values);
            $average = array_sum($values) / count($values);

            $values = array_filter(
                $values,
                function ($value) use ($stdDev, $average) {
                    return $value <= ($average + $stdDev * 3) &&
                           $value >= ($average - $stdDev * 3);
                }
            );
        }

        return $values;
    }

    /**
     * Calculate the standard deviation of a sample
     *
     * @param array
     * @return float
     */
    private function stdDev(array $values): float
    {
        $average = array_sum($values) / count($values);

        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $average, 2);
        }

        $variance = $variance / count($values);

        return sqrt($variance);
    }

    /**
     * @param Asset $asset
     * @return int
     */
    private function getBaseImageSize(Asset $asset): int
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        return $this->zfsService->getUsedByDataset($zfsPath);
    }

    /**
     * @param Asset $asset
     * @return int|null
     */
    private function getLocalTotalUsedSize(Asset $asset)
    {
        try {
            return $asset->getDataset()->getUsedSize();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param Asset $asset
     * @return int
     */
    private function getOffsiteTotalUsedSize(Asset $asset): int
    {
        $zfsPath = $asset->getDataset()->getZfsPath();
        $entry = $this->getSpeedSyncCacheEntry($zfsPath);
        $used = $entry->getRemoteUsedSize();

        if ($used === null) {
            $used = $this->speedsync->getRemoteUsedSize($zfsPath);
            $entry->setRemoteUsedSize($used);
        }

        return $used;
    }

    /**
     * @param string $zfsPath
     * @return SpeedSyncCacheEntry
     */
    private function getSpeedSyncCacheEntry(string $zfsPath): SpeedSyncCacheEntry
    {
        $entry = $this->speedsyncCache->getEntry($zfsPath);

        if ($entry === null) {
            $entry = new SpeedSyncCacheEntry($zfsPath);
            $this->speedsyncCache->setEntry($zfsPath, $entry);
        }

        return $entry;
    }

    /**
     * @param RecoveryPoints $recoveryPoints
     * @return int|null
     */
    private function getLatestSnapshotEpoch(RecoveryPoints $recoveryPoints)
    {
        $recoveryPoint = $recoveryPoints->getLast();
        if ($recoveryPoint) {
            return $recoveryPoint->getEpoch();
        } else {
            return null;
        }
    }
}
