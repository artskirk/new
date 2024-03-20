<?php

namespace Datto\Metrics\Offsite;

use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSync;
use Datto\Asset\Asset;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Share\Share;
use Datto\Asset\OffsiteMetricPoint;
use Exception;

/**
 * Class: OffsiteMetricsInitializer is a one-time use class to initialize / migrate offsite metrics
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class OffsiteMetricsInitializer
{
    const RETENTION_IN_SECS = 5184000; // 60 days in seconds. 60 * 60 * 24 * 60 (retention days)

    /** @var AssetService */
    protected $assetService;

    /** @var SpeedSync */
    protected $speedSync;

    /**
     * @param AssetService|null $assetService
     * @param SpeedSync|null $speedSync
     */
    public function __construct(
        AssetService $assetService = null,
        SpeedSync $speedSync = null
    ) {
        $this->assetService = $assetService ?: new AssetService();
        $this->speedSync = $speedSync ?: new SpeedSync();
    }

    /**
     * Initializes the queued and completed offsite metric points for all assets.
     * This should only be called once to initialize all assets.
     */
    public function initializeOffsiteMetricPointsFromSystem()
    {
        $assets = $this->assetService->getAll();
        foreach ($assets as $asset) {
            try {
                $this->initializeOffsiteMetricPointsForAsset($asset);
            } catch (Exception $e) {
                // The call to speedsync with an asset that has never had offsite points
                // will throw an exception.  If the asset has never had offsite points, there is
                // nothing to initialize, so move on to the next asset.
                continue;
            }
        }
    }

    /**
     * Initializes the queued and completed offsite metric points for a given asset.
     *
     * @param Asset $asset Asset to initialize offsite metric points for
     */
    private function initializeOffsiteMetricPointsForAsset(Asset $asset)
    {
        // Add existing completed recovery points to both the queued and completed offsite metrics
        $completedPoints = $this->getCompletedPointsForAsset($asset);
        $atLeastOnePointWasAdded = $this->addQueuedPointsToAsset($asset, $completedPoints);
        $atLeastOnePointWasAdded = $this->addCompletedPointsToAsset($asset, $completedPoints) || $atLeastOnePointWasAdded;

        // Add the currently queued recovery points to the queued offsite metrics
        $queuedPoints = $this->getQueuedPointsForAsset($asset);
        $currentQueuedPoints = array_diff($queuedPoints, $completedPoints);
        $atLeastOnePointWasAdded = $this->addQueuedPointsToAsset($asset, $currentQueuedPoints) || $atLeastOnePointWasAdded;

        if ($atLeastOnePointWasAdded) {
            $this->assetService->save($asset);
        }
    }

    /**
     * Retrieves the queued and protected points for a given asset.
     *
     * @param Asset $asset Asset to retrieve the queued points for
     * @return integer[] List of queued and protected offsite recovery points
     */
    private function getQueuedPointsForAsset(Asset $asset)
    {
        /** @var Agent|Share $asset */
        $zfsPath = $asset->getDataset()->getZfsPath();

        $queuedPoints = $this->speedSync->getQueuedPoints($zfsPath);

        $retentionTime = time() - self::RETENTION_IN_SECS;
        $filteredQueuedPoints = array_filter($queuedPoints, function ($recoveryPoint) use ($retentionTime) {
            return $recoveryPoint >= $retentionTime;
        });

        return $filteredQueuedPoints;
    }

    /**
     * Retrieves the completed points for a given asset.
     *
     * @param Asset $asset Asset to retrieve the completed points for
     * @return integer[] List of completed offsite recovery points
     */
    private function getCompletedPointsForAsset(Asset $asset)
    {
        $completedPoints = $asset->getOffsite()->getRecoveryPoints()->getAllRecoveryPointTimes();

        $retentionTime = time() - self::RETENTION_IN_SECS;
        $filteredCompletedPoints = array_filter($completedPoints, function ($recoveryPoint) use ($retentionTime) {
            return $recoveryPoint >= $retentionTime;
        });

        return $filteredCompletedPoints;
    }

    /**
     * Add queued offsite recovery points to the given asset's offsite metrics points.
     *
     * @param Asset $asset Asset to add queued points to
     * @param integer[] $queuedPoints List of offsite recovery points
     * @return bool True if at least one point was added, False otherwise
     */
    private function addQueuedPointsToAsset(Asset $asset, $queuedPoints)
    {
        $atLeastOnePointWasAdded = false;

        if (count($queuedPoints) > 0) {
            foreach ($queuedPoints as $queuedPoint) {
                $wasAdded = $this->addQueuedPointToOffsiteMetrics($asset, $queuedPoint);
                if ($wasAdded) {
                    $atLeastOnePointWasAdded = true;
                }
            }
        }

        return $atLeastOnePointWasAdded;
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
        // We do not know when the point was queued, so we are using the snapshot time as the queued timestamp as well.
        // This will appear that the recovery point was queued to be sent off-site at the exact time the snapshot occurred.
        $queuedPoint = new OffsiteMetricPoint($recoveryPoint, $recoveryPoint);
        $offsiteMetrics = $asset->getOffsite()->getOffsiteMetrics();
        $queuedPointExists = $offsiteMetrics->doesQueuedPointExist($queuedPoint);

        if (!$queuedPointExists) {
            $offsiteMetrics->addQueuedPoint($queuedPoint);
            $wasAdded = true;
        }

        return $wasAdded;
    }

    /**
     * Add completed offsite recovery points to the given asset's offsite metrics points.
     *
     * @param Asset $asset Asset to add completed points to
     * @param integer[] $completedPoints List of offsite recovery points
     * @return bool True if at least one point was added, False otherwise
     */
    private function addCompletedPointsToAsset(Asset $asset, $completedPoints)
    {
        $atLeastOnePointWasAdded = false;

        if (count($completedPoints) > 0) {
            foreach ($completedPoints as $completedPoint) {
                $wasAdded = $this->addCompletedPointToOffsiteMetrics($asset, $completedPoint);
                if ($wasAdded) {
                    $atLeastOnePointWasAdded = true;
                }
            }
        }

        return $atLeastOnePointWasAdded;
    }

    /**
     * Attempts to add a completed recovery point to the offsite metrics completed points list.
     * If a completed point for that recovery point already exists, the recovery point will not be added.
     *
     * @param Asset $asset Asset to add completed point to
     * @param integer $recoveryPoint Recovery point to be added
     * @return bool True if the recovery point was added to the offsite metric completed points list, False otherwise
     */
    private function addCompletedPointToOffsiteMetrics(Asset $asset, $recoveryPoint)
    {
        $wasAdded = false;
        // We do not know when the point was completed, so we are using the snapshot time as the completed timestamp as well.
        // This will appear that the off-site process completed at the exact time the snapshot occurred.
        $completedPoint = new OffsiteMetricPoint($recoveryPoint, $recoveryPoint);
        $offsiteMetrics = $asset->getOffsite()->getOffsiteMetrics();
        $completedPointExists = $offsiteMetrics->doesCompletedPointExist($completedPoint);

        if (!$completedPointExists) {
            $offsiteMetrics->addCompletedPoint($completedPoint);
            $wasAdded = true;
        }

        return $wasAdded;
    }
}
