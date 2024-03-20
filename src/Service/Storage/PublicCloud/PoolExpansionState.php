<?php

namespace Datto\Service\Storage\PublicCloud;

use Datto\Config\JsonConfigRecord;

/**
 * JsonConfigRecord object representing the current state of the storage pool expansion process. Valid states are
 * success, running, and failed. stateChangedAt can be used to ensure that a new expansion is not requested too soon
 * after a previous failure.
 *
 * NOTE: the state file ends up in /var/lib/datto/device/poolExpansionState
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PoolExpansionState extends JsonConfigRecord
{
    public const SUCCESS = 'success';
    public const RUNNING = 'running';
    public const FAILED = 'failed';
    public const INITIAL_STATE_CHANGED_AT = 0;

    private const POOL_EXPANSION_STATE_KEY_FILE = 'poolExpansionState';
    private const STATE = 'state';
    private const STATE_CHANGED_AT = 'stateChangedAt';

    private string $state;
    private int $stateChangedAt;

    public function __construct()
    {
        $this->load([]);
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getStateChangedAt(): int
    {
        return $this->stateChangedAt;
    }

    public function setState(string $state)
    {
        $this->state = $state;
    }

    public function setStateChangedAt(int $stateChangedAt)
    {
        $this->stateChangedAt = $stateChangedAt;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            self::STATE => $this->state,
            self::STATE_CHANGED_AT => $this->stateChangedAt
        ];
    }

    public function getKeyName(): string
    {
        return self::POOL_EXPANSION_STATE_KEY_FILE;
    }

    protected function load(array $vals)
    {
        $this->state = $vals[self::STATE] ?? self::SUCCESS;
        $this->stateChangedAt = $vals[self::STATE_CHANGED_AT] ?? self::INITIAL_STATE_CHANGED_AT;
    }
}
