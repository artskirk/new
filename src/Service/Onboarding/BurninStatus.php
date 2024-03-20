<?php

namespace Datto\Service\Onboarding;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class BurninStatus
{
    const NO_PID_FOUND = -2;

    const STATE_NEVER_RUN = 'never_run';
    const STATE_PENDING = 'pending';
    const STATE_RUNNING = 'running';
    const STATE_ERROR = 'error';
    const STATE_FINISHED = 'finished';

    /** @var string */
    private $state;

    /** @var int|null */
    private $pid;

    /** @var int|null */
    private $startedAt;

    /** @var int|null */
    private $finishedAt;

    /** @var string|null */
    private $errorMessage;

    /**
     * @param string $state
     */
    public function __construct(string $state)
    {
        $this->state = $state;
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    public function getPid(): int
    {
        if (!isset($this->pid)) {
            throw new \Exception('PID not set');
        }

        return $this->pid;
    }

    public function getStartedAt(): int
    {
        if (!isset($this->startedAt)) {
            throw new \Exception('Start timestamp not set');
        }

        return $this->startedAt;
    }

    public function getFinishedAt(): int
    {
        if (!isset($this->finishedAt)) {
            throw new \Exception('Finish timestamp not set');
        }

        return $this->finishedAt;
    }

    public function getErrorMessage(): string
    {
        if (!isset($this->errorMessage)) {
            throw new \Exception('Start timestamp not set');
        }

        return $this->errorMessage;
    }

    public static function neverRun(): self
    {
        return new self(self::STATE_NEVER_RUN);
    }

    public static function pending(): self
    {
        return new self(self::STATE_PENDING);
    }

    public static function running(int $pid, int $startedAt): self
    {
        $status = new self(self::STATE_RUNNING);
        $status->pid = $pid;
        $status->startedAt = $startedAt;

        return $status;
    }

    public static function error(int $startedAt, int $finishedAt, string $errorMessage): self
    {
        $status = new self(self::STATE_ERROR);
        $status->startedAt = $startedAt;
        $status->finishedAt = $finishedAt;
        $status->errorMessage = $errorMessage;

        return $status;
    }

    public static function finished(int $startedAt, int $finishedAt): self
    {
        $status = new self(self::STATE_FINISHED);
        $status->startedAt = $startedAt;
        $status->finishedAt = $finishedAt;

        return $status;
    }

    public function toArray(): array
    {
        $status = [
            'state' => $this->state
        ];

        switch ($this->state) {
            case self::STATE_RUNNING:
                $status['pid'] = $this->pid;
                $status['startedAt'] = $this->startedAt;
                break;

            case self::STATE_FINISHED:
                $status['startedAt'] = $this->startedAt;
                $status['finishedAt'] = $this->finishedAt;
                break;

            case self::STATE_ERROR:
                $status['startedAt'] = $this->startedAt;
                $status['finishedAt'] = $this->finishedAt;
                $status['errorMessage'] = $this->errorMessage;
                break;
        }

        return $status;
    }

    public static function fromArray($status): self
    {
        $state = $status['state'] ?? null;

        switch ($state) {
            case self::STATE_PENDING:
                return self::pending();

            case self::STATE_RUNNING:
                return self::running(
                    $status['pid'] ?? self::NO_PID_FOUND,
                    $status['startedAt'] ?? -1
                );

            case self::STATE_FINISHED:
                return self::finished(
                    $status['startedAt'] ?? -1,
                    $status['finishedAt'] ?? -1
                );

            case self::STATE_ERROR:
                return self::error(
                    $status['startedAt'] ?? -1,
                    $status['finishedAt'] ?? -1,
                    $status['errorMessage'] ?? 'No error message available'
                );

            default:
                return self::neverRun();
        }
    }
}
