<?php

namespace Datto\Asset;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class AssetRemovalStatus
{
    const NO_PID_FOUND = -2;

    const STATE_PENDING = 'pending';
    const STATE_REMOVING = 'removing';
    const STATE_REMOVED = 'removed';
    const STATE_ERROR = 'error';
    const STATE_NONE = 'none'; // Asset is not flagged for removal and removal has not been attempted.

    const ERROR_CODE_PROCESS_DIED = 1;
    const ERROR_CODE_PROCESS_HUNG = 2;

    /** @var string */
    private $state;

    /** @var int */
    private $pid;

    /** @var int */
    private $errorCode;

    /** @var string */
    private $errorMessage;

    /** @var int */
    private $removedAt;

    /** @var bool */
    private $force;

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

    /**
     * @return int
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * @return int
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * @return int
     */
    public function getRemovedAt(): int
    {
        return $this->removedAt;
    }

    /**
     * @return bool True if this is a force removal
     */
    public function isForce(): bool
    {
        return $this->force;
    }

    /**
     * @return AssetRemovalStatus
     */
    public static function none()
    {
        $status = new self(self::STATE_NONE);
        return $status;
    }

    /**
     * @param bool $force Whether to force asset removal
     * @return AssetRemovalStatus
     */
    public static function pending(bool $force)
    {
        $status = new self(self::STATE_PENDING);
        $status->force = $force;
        return $status;
    }

    /**
     * @param int $pid
     * @return AssetRemovalStatus
     */
    public static function removing(int $pid)
    {
        $status = new self(self::STATE_REMOVING);
        $status->pid = $pid;
        return $status;
    }

    /**
     * @param int $errorCode
     * @param string $errorMessage
     * @return AssetRemovalStatus
     */
    public static function error(int $errorCode, string $errorMessage)
    {
        $status = new self(self::STATE_ERROR);
        $status->errorCode = $errorCode;
        $status->errorMessage = $errorMessage;
        return $status;
    }

    /**
     * @param int $removedAt
     * @return AssetRemovalStatus
     */
    public static function removed(int $removedAt)
    {
        $status = new self(self::STATE_REMOVED);
        $status->removedAt = $removedAt;
        return $status;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $status = [
            'state' => $this->state
        ];

        switch ($this->state) {
            case self::STATE_PENDING:
                $status['force'] = $this->force;
                break;

            case self::STATE_REMOVING:
                $status['pid'] = $this->pid;
                break;

            case self::STATE_REMOVED:
                $status['removedAt'] = $this->removedAt;
                break;

            case self::STATE_ERROR:
                $status['errorCode'] = $this->errorCode;
                $status['errorMessage'] = $this->errorMessage;
                break;
        }

        return $status;
    }

    /**
     * @param array|null $status
     * @return AssetRemovalStatus
     */
    public static function fromArray($status)
    {
        $state = $status['state'] ?? null;

        switch ($state) {
            case self::STATE_REMOVING:
                return self::removing($status['pid'] ?? self::NO_PID_FOUND);

            case self::STATE_ERROR:
                return self::error($status['errorCode'] ?? 0, $status['errorMessage'] ?? 'Malformed status file');

            case self::STATE_PENDING:
                return self::pending($status['force'] ?? false);

            case self::STATE_REMOVED:
                return self::removed($status['removedAt'] ?? 0);

            default:
                return self::none();
        }
    }
}
