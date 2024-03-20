<?php

namespace Datto\Asset;

/**
 * Encapsulates the result of application and service verifications.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ApplicationResult
{
    /**
     * Application was detected in a running state.
     */
    const RUNNING = 1;

    /**
     * Application was not detected in a running state.
     */
    const NOT_RUNNING = 2;

    /**
     * We were not able to determine if this application was running or not.
     */
    const ERROR_NOT_EXECUTED = 3;

    /** @var string */
    private $name;

    /** @var int */
    private $status;

    /**
     * @param string $name
     * @param int $status
     */
    public function __construct(string $name, int $status)
    {
        $this->name = $name;
        $this->status = $status;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /**
     * @return bool
     */
    public function detectionCompleted(): bool
    {
        return $this->status === self::RUNNING || $this->status === self::NOT_RUNNING;
    }
}
