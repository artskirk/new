<?php

namespace Datto\Reporting\Backup;

/**
 * Class BackupReport
 * Class that represents a backup.
 *
 * @package Datto\Reporting\Backup
 */
class BackupReport
{
    public const FORCED_BACKUP = 'forced';
    public const SCHEDULED_BACKUP = 'scheduled';

    private int $scheduledTime;
    private int $completedTime;
    private string $type;
    /** @var BackupAttemptStatus[] */
    private array $attempts;
    private bool $success;

    /**
     * @param ?int $time Scheduled time for backup
     * @param ?string $type Type of backup (forced|scheduled)
     */
    public function __construct(int $time = null, string $type = null)
    {
        $this->scheduledTime = $time;
        $this->type = $type;
        $this->completedTime = -1;
        $this->attempts = [];
        $this->success = false;
    }

    /**
     * @return int
     */
    public function getScheduledTime(): int
    {
        return $this->scheduledTime;
    }

    /**
     * @return int
     */
    public function getCompletedTime(): int
    {
        return $this->completedTime;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return BackupAttemptStatus[]
     */
    public function getAttempts(): array
    {
        return $this->attempts;
    }

    /**
     * @return bool
     */
    public function getSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @param bool $success
     */
    public function setSuccess(bool $success)
    {
        $this->success = $success;
    }

    /**
     * @param int $completedTime
     */
    public function setCompletedTime(int $completedTime)
    {
        $this->completedTime = $completedTime;
    }

    /**
     * Converts object to array.
     *
     * @return array The object as an array
     */
    public function toArray(): array
    {
        $attempts = [];

        foreach ($this->attempts as $tmp) {
            $attempts[] = $tmp->toArray();
        }

        return array(
            'scheduledTime' => $this->scheduledTime,
            'completedTime' => $this->completedTime,
            'type' => $this->type,
            'attempts' => $attempts,
            'success' => $this->success
        );
    }

    /**
     * Adds a BackupAttemptStatus to the backup.
     * Sets the success of the backup to the attempts success.
     *
     * @param BackupAttemptStatus $attempt The attempt to add
     */
    public function addAttempt(BackupAttemptStatus $attempt)
    {
        $this->attempts[] = $attempt;
        $this->success = $this->success || $attempt->getSuccess();
    }
}
