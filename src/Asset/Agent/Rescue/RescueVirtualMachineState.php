<?php

namespace Datto\Asset\Agent\Rescue;

/**
 * A collection of information for a rescue agent's virtual machine.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RescueVirtualMachineState
{
    /** @var string */
    private $assetKey;

    /** @var int */
    private $snapshot;

    /** @var bool */
    private $isPoweredOn;

    /** @var bool */
    private $isRunning;

    /**
     * @param string $assetKey
     * @param int|null $snapshot
     * @param bool $isPoweredOn
     * @param bool $isRunning
     */
    public function __construct(string $assetKey, $snapshot, bool $isPoweredOn, bool $isRunning)
    {
        $this->assetKey = $assetKey;
        $this->snapshot = $snapshot;
        $this->isPoweredOn = $isPoweredOn;
        $this->isRunning = $isRunning;
    }

    /**
     * @return string
     */
    public function getAssetKey(): string
    {
        return $this->assetKey;
    }

    /**
     * Snapshot used to create the virtual machine initially.
     *
     * @return int|null
     */
    public function getSnapshot()
    {
        return $this->snapshot;
    }

    /**
     * Set if the virtual machine has been powered on by the user. This does not mean the virtual machine is
     * currently running.
     *
     * @return bool
     */
    public function isPoweredOn(): bool
    {
        return $this->isPoweredOn;
    }

    /**
     * Set if the virtual machine is actually running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}
