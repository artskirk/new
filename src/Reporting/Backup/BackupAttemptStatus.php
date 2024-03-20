<?php
namespace Datto\Reporting\Backup;

/**
 * Represents a backup attempt, regardless of type.
 *
 * @package Datto\Reporting\Backup
 */
class BackupAttemptStatus
{
    private int $actualStartTime;
    private bool $success;
    private ?string $code;
    private ?string $message;

    /**
     * BackupAttemptStatus constructor.
     *
     * @param int $time Actual start time for attempt.
     */
    public function __construct(int $time)
    {
        $this->actualStartTime = $time;
        $this->success = false;
        $this->code = null;
        $this->message = null;
    }

    /**
     * @return bool
     */
    public function getSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return string
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * @param string $code
     */
    public function setCode(string $code)
    {
        $this->code = $code;
    }

    /**
     * @return string
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @param ?string $message
     */
    public function setMessage(string $message = null)
    {
        $this->message = $message;
    }

    /**
     * @param bool $success
     */
    public function finish(bool $success)
    {
        $this->success = $success;
    }

    /**
     * Convert object to array.
     *
     * @return string[] Object as array
     */
    public function toArray(): array
    {
        return array(
            'time' => $this->actualStartTime,
            'success' => $this->success,
            'code' => $this->code,
            'message' => $this->message
        );
    }
}
