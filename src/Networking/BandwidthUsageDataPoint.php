<?php

namespace Datto\Networking;

/**
 * Object representing bandwidth usage data from a single time period
 * @author Kim Desorcie <kdesorcie@datto.com>
 */
class BandwidthUsageDataPoint
{
    /** @var string */
    private $timeUnit;

    /** @var int */
    private $periodNumber;

    /** @var int */
    private $receiveRate;

    /** @var int */
    private $transmitRate;

    public function __construct(
        string $timeUnit,
        int $periodNumber,
        int $receiveRate,
        int $transmitRate
    ) {
        $this->timeUnit = $timeUnit;
        $this->periodNumber = $periodNumber;
        $this->receiveRate = $receiveRate;
        $this->transmitRate = $transmitRate;
    }

    /**
     * @return string what unit of time this data represents
     */
    public function getTimeUnit(): string
    {
        return $this->timeUnit;
    }

    /**
     * @return int numeric representation of which period of time this data is for
     *  (for example, the hour number for hourly data)
     */
    public function getPeriodNumber(): int
    {
        return $this->periodNumber;
    }

    public function getReceiveRate(): int
    {
        return $this->receiveRate;
    }

    public function getTransmitRate(): int
    {
        return $this->transmitRate;
    }

    /**
     * Adds bandwidth usage to an existing BandwidthUsageDataPoint
     * @param int $receiveRate
     * @param int $transmitRate
     */
    public function addBandwidthUsage(int $receiveRate, int $transmitRate): void
    {
        $this->receiveRate += $receiveRate;
        $this->transmitRate += $transmitRate;
    }
}
