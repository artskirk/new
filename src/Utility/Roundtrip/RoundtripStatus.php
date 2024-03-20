<?php

namespace Datto\Utility\Roundtrip;

/**
 * Model class containing Roundtrip status data from roundtrip-ng.
 *
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class RoundtripStatus
{
    /** @var string */
    private $type;

    /** @var bool */
    private $running;

    /** @var int */
    private $lastFinished;

    /** @var string */
    private $lastState;

    /** @var int|null */
    private $currentTotal;

    /** @var int|null */
    private $currentStage;

    /** @var string|null */
    private $speed;

    /** @var string|null */
    private $percent;

    /** @var float|null */
    private $timeLeft;

    /** @var string|null */
    private $totalSize;

    /** @var string|null */
    private $totalComplete;

    /**
     * @param string $type
     * @param bool $running
     * @param int $lastFinished
     * @param string $lastState
     * @param int|null $currentTotal
     * @param int|null $currentStage
     * @param string|null $speed
     * @param string|null $percent
     * @param double|null $timeLeft
     * @param string|null $totalSize
     * @param string|null $totalComplete
     */
    public function __construct(
        string $type,
        bool $running,
        int $lastFinished,
        string $lastState,
        int $currentTotal = null,
        int $currentStage = null,
        string $speed = null,
        string $percent = null,
        float $timeLeft = null,
        string $totalSize = null,
        string $totalComplete = null
    ) {
        $this->type = $type;
        $this->running = $running;
        $this->lastFinished = $lastFinished;
        $this->lastState = $lastState;
        $this->currentTotal = $currentTotal;
        $this->currentStage = $currentStage;
        $this->speed = $speed;
        $this->percent = $percent;
        $this->timeLeft = $timeLeft;
        $this->totalSize = $totalSize;
        $this->totalComplete = $totalComplete;
    }

    /**
     * @return string The type of Roundtrip transfer, can be "USB" or "NAS".
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return int Unix timestamp indicating when the last Roundtrip sync finished.
     */
    public function getLastFinished(): int
    {
        return $this->lastFinished;
    }

    /**
     * @return string String indicating the state of the last Roundtrip sync, can be "OKAY" or "FAILED".
     */
    public function getLastState(): string
    {
        return $this->lastState;
    }

    /**
     * @return bool Whether or not Roundtrip sync is currently running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @return int|null Total number of agents and shares to sync.
     */
    public function getCurrentTotal()
    {
        return $this->currentTotal;
    }

    /**
     * @return int|null Number of stages complete in Roundtrip sync, plus one.
     */
    public function getCurrentStage()
    {
        return $this->currentStage;
    }

    /**
     * @return string|null Human-readable speed of current transfer, suffixed with units. (KB/S, MB/S, etc.)
     */
    public function getSpeed()
    {
        return $this->speed;
    }

    /**
     * @return string|null Percent complete string for current transfer, to two decimal places.
     */
    public function getPercent()
    {
        return $this->percent;
    }

    /**
     * @return float|null Estimated time left in current transfer, in seconds.
     */
    public function getTimeLeft()
    {
        return $this->timeLeft;
    }

    /**
     * @return string|null Human-readable total size of current transfer, suffixed with units. (KB, MB, etc.)
     */
    public function getTotalSize()
    {
        return $this->totalSize;
    }

    /**
     * @return string|null Human-readable total complete in current transfer, suffixed with units. (KB, MB, etc.)
     */
    public function getTotalComplete()
    {
        return $this->totalComplete;
    }
}
