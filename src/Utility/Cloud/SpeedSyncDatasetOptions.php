<?php

namespace Datto\Utility\Cloud;

/**
 * Represents the result of a call to "speedsync options someZfsPath" to get options for a dataset.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SpeedSyncDatasetOptions
{
    /** @var int */
    private $priority;

    /** @var string */
    private $target;

    /** @var int */
    private $pauseZfs;

    /** @var int */
    private $pauseTransfer;

    /**
     * @param int $priority
     * @param string $target
     * @param int $pauseZfs
     * @param int $pauseTransfer
     */
    public function __construct(int $priority, string $target, int $pauseZfs, int $pauseTransfer)
    {
        $this->priority = $priority;
        $this->target = $target;
        $this->pauseZfs = $pauseZfs;
        $this->pauseTransfer = $pauseTransfer;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @return string
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * @return bool
     */
    public function isZfsPaused(): bool
    {
        return $this->pauseZfs > 0;
    }

    /**
     * @return bool
     */
    public function isTransferPaused(): bool
    {
        return $this->pauseTransfer > 0;
    }
}
