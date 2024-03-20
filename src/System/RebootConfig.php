<?php
namespace Datto\System;

/**
 * Class RebootConfig
 *  Represents reboot schedule info.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class RebootConfig
{
    /** @var  int Timestamp at which device is to be rebooted */
    private $rebootAt;

    /** @var  int Timestamp at which reboot schedule was created */
    private $createdAt;

    /** @var  bool Whether or not the reboot is being attempted */
    private $attemptingReboot;

    /** @var bool Whether the reboot attempt failed */
    private $hasFailed;

    /**
     * @param int $rebootAt Timestamp at which device is to be rebooted.
     * @param int $createdAt Timestamp at which reboot schedule was created
     * @param bool $attemptingReboot Whether or not the reboot is being attempted
     * @param bool $hasFailed
     */
    public function __construct(
        int $rebootAt = 0,
        int $createdAt = 0,
        bool $attemptingReboot = false,
        bool $hasFailed = false
    ) {
        $this->rebootAt = $rebootAt;
        $this->createdAt = $createdAt;
        $this->attemptingReboot = $attemptingReboot;
        $this->hasFailed = $hasFailed;
    }

    /**
     * Returns the timestamp at which device is to be rebooted.
     *
     * @return int Timestamp at which device is to be rebooted.
     */
    public function getRebootAt(): int
    {
        return $this->rebootAt;
    }

    /**
     * Returns the timestamp at which reboot schedule was created.
     *
     * @return int Timestamp at which reboot schedule was created.
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * Returns whether or not reboot was attempted
     *
     * @return bool Whether or not reboot was attempted
     */
    public function isAttemptingReboot(): bool
    {
        return $this->attemptingReboot;
    }

    /**
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->hasFailed;
    }
}
