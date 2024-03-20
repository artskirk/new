<?php

namespace Datto\Metrics\Offsite;

use Datto\Asset\AssetService;
use Datto\Asset\Asset;
use Datto\Asset\AssetException;
use Datto\Asset\OffsiteMetricPoint;
use DateTime;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;

/**
 * Class: OffsiteMetricsService persists metrics around offsite retention points
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class OffsiteMetricsService
{
    const RETENTION_IN_DAYS = 60;
    const UNIX_TIMESTAMP_FORMAT = 'U'; // Seconds since the Unix Epoch (January 1 1970 00:00:00 GMT)

    /** @var AssetService */
    protected $assetService;

    /** @var Collector */
    private $collector;

    public function __construct(
        AssetService $assetService,
        Collector $collector
    ) {
        $this->assetService = $assetService;
        $this->collector = $collector;
    }

    /**
     * Returns the number of queued points that exist within the given time frame across all assets.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return int Number of queued points that exist within the given time frame
     */
    public function getCountQueuedPoints($startTime, $endTime)
    {
        $total = 0;
        $assets = $this->assetService->getAll();
        
        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $total += $asset->getOffsite()->getOffsiteMetrics()->getCountQueuedPoints($startTime, $endTime);
        }

        return $total;
    }

    /**
     * Returns the number of completed points that exist within the given time frame across all assets.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return int Number of completed points that exist within the given time frame
     */
    public function getCountCompletedPoints($startTime, $endTime)
    {
        $total = 0;
        $assets = $this->assetService->getAll();

        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $total += $asset->getOffsite()->getOffsiteMetrics()->getCountCompletedPoints($startTime, $endTime);
        }

        return $total;
    }

    /**
     * Returns the number of total queued points that exist up to the inspection time across all assets.
     * Inspection time is exclusive.
     *
     * @param integer $inspectionTime Epoch point in time in which to determine total queued points up to. (Exclusive)
     * @return int Number of total queued points that exist up to the inspection time
     */
    public function getTotalQueuedPointsAtSpecificTime($inspectionTime)
    {
        $total = 0;
        $assets = $this->assetService->getAll();

        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $total += $asset->getOffsite()->getOffsiteMetrics()->getTotalQueuedPointsAtSpecificTime($inspectionTime);
        }

        return $total;
    }

    /**
     * Add an offsite metric point with a given recovery point to the asset's queued list.
     *
     * @param string $assetName Name of the asset
     * @param integer $recoveryPoint Epoch time of the recovery point
     */
    public function addQueuedPoint($assetName, $recoveryPoint)
    {
        try {
            $asset = $this->assetService->get($assetName);
        } catch (AssetException $exception) {
            // Ignore. This may occur for configBackup.
            return;
        }

        $wasAdded = $this->addQueuedPointToOffsiteMetrics($asset, $recoveryPoint);
        if ($wasAdded) {
            $this->collectMetric(Metrics::OFFSITE_POINT_QUEUED);
            $this->assetService->save($asset);
        }
    }

    /**
     * Add completed points across all assets.
     */
    public function updateCompletedPoints()
    {
        $assets = $this->assetService->getAll();
        foreach ($assets as $asset) {
            $this->updateAssetCompletedPoints($asset);
        }
    }

    /**
     * Removes offsite metric points that are older than the number of retention days across all assets.
     */
    public function prune()
    {
        $today = new DateTime();
        $primaryRemovalDate = new DateTime($today->format(DateTime::ISO8601));
        $primaryRemovalDate->modify('-' . self::RETENTION_IN_DAYS . ' days');
        $removeTime = $primaryRemovalDate->format(self::UNIX_TIMESTAMP_FORMAT);

        $assets = $this->assetService->getAll();

        /** @var Asset $asset */
        foreach ($assets as $asset) {
            $offsiteMetrics = $asset->getOffsite()->getOffsiteMetrics();
            $offsiteMetrics->removeQueuedPointsOlderThanDate($removeTime);
            $offsiteMetrics->removeCompletedPointsOlderThanDate($removeTime);
            $this->assetService->save($asset);
        }
    }

    /**
     * Add completed points for a given asset.
     *
     * @param Asset $asset Asset to add the completed points to.
     */
    private function updateAssetCompletedPoints(Asset $asset)
    {
        $completedPoints = $asset->getOffsite()->getRecoveryPoints();
        $cachedCompletedPoints = $asset->getOffsite()->getRecoveryPointsCache();

        $completedPointsArray = $completedPoints->getAllRecoveryPointTimes();
        $cacheCompletedPointsArray = $cachedCompletedPoints->getAllRecoveryPointTimes();

        $newlyCompletedPointsArray = array_diff($completedPointsArray, $cacheCompletedPointsArray);
        if (count($newlyCompletedPointsArray) > 0) {
            foreach ($newlyCompletedPointsArray as $recoveryPoint) {
                $completedPoint = new OffsiteMetricPoint($recoveryPoint, time());

                $offsiteMetrics = $asset->getOffsite()->getOffsiteMetrics();
                $queuedPointExists = $offsiteMetrics->doesQueuedPointExist($completedPoint);
                $completedPointExists = $offsiteMetrics->doesCompletedPointExist($completedPoint);

                if ($queuedPointExists && !$completedPointExists) {
                    $this->collectMetric(Metrics::OFFSITE_POINT_COMPLETED);
                    $asset->getOffsite()->getOffsiteMetrics()->addCompletedPoint($completedPoint);
                }
            }

            $asset->getOffsite()->setRecoveryPointsCache($completedPoints);
            $this->assetService->save($asset);
        }
    }

    /**
     * Attempts to add a queued offsite recovery point to the offsite metrics queued points list.
     * If a queued point for that recovery point already exists, the recovery point will not be added.
     *
     * @param Asset $asset Asset to add queued point to
     * @param integer $recoveryPoint Recovery point to be added
     * @return bool True if the recovery point was added to the offsite metric queued points list, False otherwise
     */
    private function addQueuedPointToOffsiteMetrics(Asset $asset, $recoveryPoint)
    {
        $wasAdded = false;
        $queuedPoint = new OffsiteMetricPoint($recoveryPoint, time());
        $offsiteMetrics = $asset->getOffsite()->getOffsiteMetrics();
        $queuedPointExists = $offsiteMetrics->doesQueuedPointExist($queuedPoint);

        if (!$queuedPointExists) {
            $offsiteMetrics->addQueuedPoint($queuedPoint);
            $wasAdded = true;
        }

        return $wasAdded;
    }

    private function collectMetric(string $metric)
    {
        $this->collector->increment($metric, ['resumability' => 'resumable']);
    }
}
