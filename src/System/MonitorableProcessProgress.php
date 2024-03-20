<?php

namespace Datto\System;

/**
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class MonitorableProcessProgress
{
    /** @param int */
    private $bytesTransferred;

    /** @param int */
    private $percentComplete;

    /** @param string */
    private $transferRate;

    /**
     * @param int $bytesTransferred
     * @param int $percentComplete
     * @param string $transferRate
     */
    public function __construct(
        $bytesTransferred,
        $percentComplete,
        $transferRate
    ) {
        $this->bytesTransferred = $bytesTransferred;
        $this->percentComplete = $percentComplete;
        $this->transferRate = $transferRate;
    }

    /**
     * The number of bytes transferred thus far.
     * @return int
     */
    public function getBytesTransferred(): int
    {
        return $this->bytesTransferred;
    }

    /**
     * The percentage complete
     * @return int
     */
    public function getPercentComplete(): int
    {
        return $this->percentComplete;
    }

    /**
     * The current transfer rate
     * @return string
     */
    public function getTransferRate(): string
    {
        return $this->transferRate;
    }
}
