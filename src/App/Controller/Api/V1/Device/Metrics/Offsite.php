<?php

namespace Datto\App\Controller\Api\V1\Device\Metrics;

use Datto\Log\LoggerFactory;
use Datto\Metrics\Offsite\OffsiteMetricsService;
use Exception;
use DateTime;

/**
 * API endpoint for metrics about offsite jobs performed by speedsync
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
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class Offsite
{
    const UNIX_TIMESTAMP_FORMAT = 'U'; // Seconds since the Unix Epoch (January 1 1970 00:00:00 GMT)

    /** @var OffsiteMetricsService */
    private $offsiteMetricsService;

    public function __construct(OffsiteMetricsService $offsiteMetricsService)
    {
        $this->offsiteMetricsService = $offsiteMetricsService;
    }

    /**
     * Provides up to 60 days of data about speedsync job queues.
     *
     * *   queuedPoints - describes the number of queued jobs per day
     * *   completedPoints - describes the number of completed jobs per day
     * *   queuedPointsTotal - cumulative queue total per day. This includes rollover from previous day(s).
     *
     * Since the server may be offline periodically, metric points for certain
     * days may be omitted from the returned datasets. If metrics cannot be read,
     * an error is returned (code 500).
     *
     * FIXME This should be moved to v1/device/offsite
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return array
     */
    public function getJobQueueMetrics()
    {
        $responseData = null;

        try {
            $responseData = $this->getMetricsArrays();
        } catch (Exception $ex) {
            // we don't want the api to return technical details to the user, but
            // it should be logged for diagnostic purposes.
            LoggerFactory::getDeviceLogger()->info(
                'API0000 Failed to get metrics. Reason: ' . $ex->getMessage()
            );
            throw new Exception("A problem occured getting the metrics.", 500);
        }

        return $responseData;
    }

    private function getMetricsArrays(): array
    {
        $queuedPoints = array();
        $queuedTotalPoints = array();
        $completedPoints = array();

        $endTime = new DateTime();
        $startTime = new DateTime($endTime->format(DateTime::ISO8601));
        $startTime->modify('-7 days');

        for ($i = 0; $i < 8; ++$i) {
            $startEpoch = $startTime->format(self::UNIX_TIMESTAMP_FORMAT);
            $endEpoch = $endTime->format(self::UNIX_TIMESTAMP_FORMAT);

            $queuedPointsForDay = $this->offsiteMetricsService->getCountQueuedPoints($startEpoch, $endEpoch);
            $queuedPoint = array(
                'date' => $endTime->format(self::UNIX_TIMESTAMP_FORMAT),
                'number' => $queuedPointsForDay
            );
            $queuedPoints[] = $queuedPoint;

            $completedPointsForDay = $this->offsiteMetricsService->getCountCompletedPoints($startEpoch, $endEpoch);
            $completedPoint = array(
                'date' => $endTime->format(self::UNIX_TIMESTAMP_FORMAT),
                'number' => $completedPointsForDay
            );
            $completedPoints[] = $completedPoint;

            $queuedTotalForDay = $this->offsiteMetricsService->getTotalQueuedPointsAtSpecificTime($endEpoch);
            $queuedTotalPoint = array(
                'date' => $endTime->format(self::UNIX_TIMESTAMP_FORMAT),
                'number' => $queuedTotalForDay
            );
            $queuedTotalPoints[] = $queuedTotalPoint;

            // Shift time frame to previous week
            $startTime->modify('-7 days');
            $endTime->modify('-7 days');
        }

        $metricArrays['queuedPointsDaily'] = $queuedPoints;
        $metricArrays['queuedPointsTotal'] = $queuedTotalPoints;
        $metricArrays['completedPoints'] = $completedPoints;

        return $metricArrays;
    }
}
