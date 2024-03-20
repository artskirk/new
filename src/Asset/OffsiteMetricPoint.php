<?php

namespace Datto\Asset;

/**
 * Class OffsiteMetricPoint holds the timestamped metric value
 *
 *  @author Jeffrey Knapp <jknapp@datto.com>
 */
class OffsiteMetricPoint
{
    /** @var integer Epoch time of the recovery point */
    private $recoveryPointTime;

    /** @var integer Epoch time of the action taken on the recovery point (queued, completed, etc.) */
    private $timestamp;

    /**
     * Construct an OffsiteMetricPoint object
     *
     * @param integer $recoveryPointTime Epoch time of the recovery point
     * @param integer $timestamp Epoch time of the action taken on the recovery point (queued, completed, etc.)
     */
    public function __construct(
        $recoveryPointTime = null,
        $timestamp = null
    ) {
        $this->recoveryPointTime = intval($recoveryPointTime) ?: 0;
        $this->timestamp = intval($timestamp) ?: 0;
    }

    /**
     * Return the epoch time of the recovery point
     *
     * @return integer
     */
    public function getRecoveryPointTime()
    {
        return $this->recoveryPointTime;
    }

    /**
     * Return the epoch time of the action taken on the recovery point (queued, completed, etc.)
     *
     * @return integer
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }
}
