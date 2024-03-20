<?php

namespace Datto\Core\Storage;

/**
 * Data object class that contains information about a storage pool status.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class StoragePoolStatus
{
    private string $poolId;
    private string $state;
    private string $status;
    private string $action;
    private string $scan;
    private array $errors;

    /** @var StoragePoolDeviceStatus[] */
    private array $devices;

    public function __construct(
        string $poolId,
        string $state,
        string $status,
        string $action,
        string $scan,
        array $errors,
        array $devices
    ) {
        $this->poolId = $poolId;
        $this->state = $state;
        $this->status = $status;
        $this->action = $action;
        $this->scan = $scan;
        $this->errors = $errors;
        $this->devices = $devices;
    }

    public function getPoolId(): string
    {
        return $this->poolId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getScan(): string
    {
        return $this->scan;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return StoragePoolDeviceStatus[]
     */
    public function getDevices(): array
    {
        return $this->devices;
    }
}
