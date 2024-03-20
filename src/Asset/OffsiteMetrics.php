<?php

namespace Datto\Asset;

/**
 * Class OffsiteMetrics holds the collection of queued and completed offsite metric points.
 *
 *  @author Jeffrey Knapp <jknapp@datto.com>
 */
class OffsiteMetrics
{
    const EARLIER_THAN = -1;
    const LATER_THAN = 1;
    const SAME_TIME = 0;

    /** @var OffsiteMetricPoint[] Queued offsite metric points */
    private $queuedPoints;

    /** @var OffsiteMetricPoint[] Completed offsite metric points */
    private $completedPoints;

    /**
     * Construct an OffsiteMetrics object.
     *
     * @param OffsiteMetricPoint[]|null $queuedPoints Queued offsite metric points
     * @param OffsiteMetricPoint[]|null $completedPoints Completed offsite metric points
     */
    public function __construct(
        $queuedPoints = null,
        $completedPoints = null
    ) {
        $this->queuedPoints = $queuedPoints ?: array();
        $this->completedPoints = $completedPoints ?: array();
    }

    /**
     * Add an offsite metric point to the queued list.
     *
     * @param OffsiteMetricPoint $queuedPoint Queued point to be added
     */
    public function addQueuedPoint(OffsiteMetricPoint $queuedPoint): void
    {
        $this->queuedPoints[] = $queuedPoint;
    }

    /**
     * Add an offsite metric point to the completed list.
     *
     * @param OffsiteMetricPoint $completedPoint Completed point to be added
     */
    public function addCompletedPoint(OffsiteMetricPoint $completedPoint): void
    {
        $this->completedPoints[] = $completedPoint;
    }

    /**
     * Removes all queued offsite metrics points that are older than the provided date / time
     *
     * @param integer $cutoffTime Epoch time to check against
     */
    public function removeQueuedPointsOlderThanDate($cutoffTime): void
    {
        $pointsToKeep = array();

        // Add the points that are greater (more recent) than the removal point to a temporary array,
        // and update the points array to the array of the ones we are keeping.
        foreach ($this->queuedPoints as $pointToCheck) {
            if ($pointToCheck->getRecoveryPointTime() >= $cutoffTime) {
                $pointsToKeep[] = $pointToCheck;
            }
        }

        $this->queuedPoints = $pointsToKeep;
    }

    /**
     * Removes all completed offsite metrics points that are older than the provided date / time
     *
     * @param integer $cutoffTime Epoch time to check against
     */
    public function removeCompletedPointsOlderThanDate($cutoffTime): void
    {
        $pointsToKeep = array();

        // Add the points that are greater (more recent) than the removal point to a temporary array,
        // and update the points array to the array of the ones we are keeping.
        foreach ($this->completedPoints as $pointToCheck) {
            if ($pointToCheck->getRecoveryPointTime() >= $cutoffTime) {
                $pointsToKeep[] = $pointToCheck;
            }
        }

        $this->completedPoints = $pointsToKeep;
    }

    /**
     * Returns the list of queued offsite metric points.
     *
     * @return array|OffsiteMetricPoint[] Queued offsite metric points
     */
    public function getAllQueuedPoints()
    {
        return $this->queuedPoints;
    }

    /**
     * Returns the list of completed offsite metric points.
     *
     * @return array|OffsiteMetricPoint[] Completed offsite metric points
     */
    public function getAllCompletedPoints()
    {
        return $this->completedPoints;
    }

    /**
     * Returns a filter list of queued offsite metric points that have a recovery time between the start and end times.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return array|OffsiteMetricPoint[] Filtered list of queued offsite metric points
     */
    public function getFilteredQueuedPoints($startTime, $endTime)
    {
        $filteredArray = array_filter($this->queuedPoints, function ($queuedPoint) use ($startTime, $endTime) {
            /** @var OffsiteMetricPoint $queuedPoint */
            $queuedTime = $queuedPoint->getTimestamp();
            return (!$startTime || $startTime <= $queuedTime) && ($queuedTime < $endTime);
        });
        $reIndexedArray = array_values($filteredArray);

        return $reIndexedArray;
    }

    /**
     * Returns a filter list of completed offsite metric points that have a recovery time between the start and end times.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return array|OffsiteMetricPoint[] Filtered list of completed offsite metric points
     */
    public function getFilteredCompletedPoints($startTime, $endTime)
    {
        $filteredArray = array_filter($this->completedPoints, function ($completedPoint) use ($startTime, $endTime) {
            /** @var OffsiteMetricPoint $completedPoint */
            $completedTime = $completedPoint->getTimestamp();
            return (!$startTime || $startTime <= $completedTime) && ($completedTime < $endTime);
        });
        $reIndexedArray = array_values($filteredArray);

        return $reIndexedArray;
    }

    /**
     * Determines if the test queued point exists in the queued offsite metrics point list based on
     * the queued points' recovery point time.
     *
     * @param OffsiteMetricPoint $testQueuedPoint Queued offsite metrics point to be tested for
     * @return boolean True if the queued offsite metrics point exists, false otherwise.
     */
    public function doesQueuedPointExist(OffsiteMetricPoint $testQueuedPoint)
    {
        $testRecoveryPointTime = $testQueuedPoint->getRecoveryPointTime();

        $filteredArray = array_filter($this->queuedPoints, function ($queuedPoint) use ($testRecoveryPointTime) {
            /** @var OffsiteMetricPoint $queuedPoint */
            $recoveryPointTime = $queuedPoint->getRecoveryPointTime();
            return $testRecoveryPointTime == $recoveryPointTime;
        });

        $queuedPointExists = (count($filteredArray) > 0);
        return $queuedPointExists;
    }

    /**
     * Determines if the test completed point exists in the completed offsite metrics point list based on
     * the completed points' recovery point time.
     *
     * @param OffsiteMetricPoint $testCompletedPoint Completed offsite metrics point to be tested for
     * @return boolean True if the completed offsite metrics point exists, false otherwise.
     */
    public function doesCompletedPointExist(OffsiteMetricPoint $testCompletedPoint)
    {
        $testRecoveryPointTime = $testCompletedPoint->getRecoveryPointTime();

        $filteredArray = array_filter($this->completedPoints, function ($completedPoint) use ($testRecoveryPointTime) {
            /** @var OffsiteMetricPoint $completedPoint */
            $recoveryPointTime = $completedPoint->getRecoveryPointTime();
            return $testRecoveryPointTime == $recoveryPointTime;
        });

        $completedPointExists = (count($filteredArray) > 0);
        return $completedPointExists;
    }

    /**
     * Returns the number of queued points that exist within the given time frame.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return int Number of queued points that exist within the given time frame
     */
    public function getCountQueuedPoints($startTime, $endTime)
    {
        return count($this->getFilteredQueuedPoints($startTime, $endTime));
    }

    /**
     * Returns the number of completed points that exist within the given time frame.
     * Start time is inclusive.  End time is exclusive.
     *
     * @param integer $startTime Epoch time of the start of the time frame to filter on. (Inclusive)
     * @param integer $endTime Epoch time of the end of the time frame to filter on. (Exclusive)
     * @return int Number of completed points that exist within the given time frame
     */
    public function getCountCompletedPoints($startTime, $endTime)
    {
        return count($this->getFilteredCompletedPoints($startTime, $endTime));
    }

    /**
     * Returns the number of total queued points that exist up to the inspection time.
     * Inspection time is exclusive.
     *
     * @param integer $inspectionTime Epoch point in time in which to determine total queued points up to. (Exclusive)
     * @return int Number of total queued points that exist up to the inspection time
     */
    public function getTotalQueuedPointsAtSpecificTime($inspectionTime)
    {
        $queuedPoints = $this->getFilteredQueuedPoints(null, $inspectionTime);
        $completedPoints = $this->getFilteredCompletedPoints(null, $inspectionTime);

        $total = count(array_udiff($queuedPoints, $completedPoints, array($this, 'compareRecoveryPointTimes')));

        return $total;
    }

    /**
     * Compares the recovery point times of a queued and completed point.
     *
     * @param OffsiteMetricPoint $queuedPoint
     * @param OffsiteMetricPoint $completedPoint
     * @return int Comparison return, -1 if queued point is earlier than completed point,
     *  1 if queued point is later than the completed point, 0 if the are equal
     */
    private function compareRecoveryPointTimes(OffsiteMetricPoint $queuedPoint, OffsiteMetricPoint $completedPoint)
    {
        $queuedPointTime = $queuedPoint->getRecoveryPointTime();
        $completedPointTime = $completedPoint->getRecoveryPointTime();

        if ($queuedPointTime < $completedPointTime) {
            return self::EARLIER_THAN;
        } elseif ($queuedPointTime > $completedPointTime) {
            return self::LATER_THAN;
        } else {
            return self::SAME_TIME;
        }
    }
}
