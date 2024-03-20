<?php

namespace Datto\System\Migration\Device;

use DateTime;

/**
 * Status of Device Migrations.
 * This class is immutable.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceMigrationStatus
{
    /* Allowable values for the "state" */
    const STATE_RUNNING = 'RUNNING';
    const STATE_INACTIVE = 'INACTIVE';
    const STATE_SUCCESS = 'SUCCESS';
    const STATE_FAILED = 'FAILED';

    /** @var string */
    private $hostname;

    /** @var DateTime */
    private $startDateTime;

    /** @var string */
    private $state;

    /** @var string */
    private $message;

    /** @var int */
    private $errorCode;
    /**
     * @param string $hostname The displayed hostname of the source device
     * @param DateTime $startDateTime Date and time when the migration started
     * @param string $state One of the "STATE_" constants above
     * @param string $message Error message (or blank if none)
     */
    public function __construct(
        string $hostname,
        DateTime $startDateTime,
        string $state,
        string $message,
        int $errorCode
    ) {
        $this->hostname = $hostname;
        $this->startDateTime = $startDateTime;
        $this->state = $state;
        $this->message = $message;
        $this->errorCode = $errorCode;
    }

    /**
     * Get the displayed hostname of the source device.
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * Get the date and time when the migration started.
     *
     * @return DateTime
     */
    public function getStartDateTime(): DateTime
    {
        return $this->startDateTime;
    }

    /**
     * Get the current state of the migration.
     * Can be one of the "STATE_" constants above.
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Get the error message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the error code. May be zero even if there is an error.
     *
     * @return int
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}
