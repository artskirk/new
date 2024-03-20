<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\OffsiteMetrics;
use Datto\Asset\OffsiteMetricPoint;

class OffsiteMetricsSerializer implements Serializer
{
    /**
     * Get data from the model and save it in the repository
     * @param OffsiteMetrics $offsiteMetrics
     * @return array[]
     */
    public function serialize($offsiteMetrics)
    {
        return json_encode($this->getMetricsArrays($offsiteMetrics));
    }

    /**
     * Load data from the repository and set the model's attributes with it
     * @param $fileArray
     * @return OffsiteMetrics
     */
    public function unserialize($fileArray)
    {
        $metricArrays = json_decode($fileArray, true);

        $queuedPoints = array();
        $points = isset($metricArrays['queuedPoints']) ? $metricArrays['queuedPoints'] : array();
        foreach ($points as $point) {
            $queuedPoint = new OffsiteMetricPoint($point['recoveryPointTime'], $point['timestamp']);
            $queuedPoints[] = $queuedPoint;
        }

        $completedPoints = array();
        $points = isset($metricArrays['completedPoints']) ? $metricArrays['completedPoints'] : array();
        foreach ($points as $point) {
            $completedPoint = new OffsiteMetricPoint($point['recoveryPointTime'], $point['timestamp']);
            $completedPoints[] = $completedPoint;
        }

        $offsiteMetrics = new OffsiteMetrics($queuedPoints, $completedPoints);

        return $offsiteMetrics;
    }
    
    /**
     * Provides metrics as a keyed array of arrays. The following
     * keys are valid:
     *
     * * queuedPoints
     * * completedPoints
     *
     * @param OffsiteMetrics $offsiteMetrics
     * @return array
     */
    private function getMetricsArrays($offsiteMetrics)
    {
        /** @var OffsiteMetricPoint $point */
        $offsiteMetrics->getAllQueuedPoints();

        $metricArrays = array();

        $serializedPoints = array();
        foreach ($offsiteMetrics->getAllQueuedPoints() as $point) {
            $serializedPoint = array(
                'recoveryPointTime' => $point->getRecoveryPointTime(),
                'timestamp' => $point->getTimestamp()
            );

            $serializedPoints[] = $serializedPoint;
        }
        $metricArrays['queuedPoints'] = $serializedPoints;

        $serializedPoints = array();
        foreach ($offsiteMetrics->getAllCompletedPoints() as $point) {
            $serializedPoint = array(
                'recoveryPointTime' => $point->getRecoveryPointTime(),
                'timestamp' => $point->getTimestamp()
            );

            $serializedPoints[] = $serializedPoint;
        }
        $metricArrays['completedPoints'] = $serializedPoints;

        return $metricArrays;
    }
}
