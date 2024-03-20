<?php
namespace Datto\Cloud;

use JsonSerializable;

/**
 * Contains the cache information for a particular dataset
 */
class SpeedSyncCacheEntry implements JsonSerializable
{
    /** @var string */
    protected $zfsPath;

    /** @var int */
    protected $updated;

    /** @var array */
    protected $offsitePoints;

    /** @var array */
    protected $criticalPoints;

    /** @var array */
    protected $remoteReplicatingPoints;

    /** @var array */
    protected $queuedPoints;

    /** @var int */
    protected $remoteUsedSize;

    /**
     * @param string $zfsPath
     * @param int $updated
     * @param int[]|null $offsitePoints
     * @param int[]|null $criticalPoints
     * @param int[]|null $remoteReplicatingPoints
     * @param int[]|null $queuedPoints
     * @param int|null $remoteUsedSize
     */
    public function __construct(
        string $zfsPath,
        int $updated = 0,
        array $offsitePoints = null,
        array $criticalPoints = null,
        array $remoteReplicatingPoints = null,
        array $queuedPoints = null,
        int $remoteUsedSize = null
    ) {
        $this->zfsPath = $zfsPath;
        $this->updated = $updated;
        $this->offsitePoints = $offsitePoints;
        $this->criticalPoints = $criticalPoints;
        $this->remoteReplicatingPoints = $remoteReplicatingPoints;
        $this->queuedPoints = $queuedPoints;
        $this->remoteUsedSize = $remoteUsedSize;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            SpeedSyncCache::UPDATED => $this->updated,
            SpeedSyncCache::OFFSITE_POINTS => $this->offsitePoints,
            SpeedSyncCache::CRITICAL_POINTS => $this->criticalPoints,
            SpeedSyncCache::REMOTE_REPLICATING_POINTS => $this->remoteReplicatingPoints,
            SpeedSyncCache::QUEUED_POINTS => $this->queuedPoints,
            SpeedSyncCache::REMOTE_USED_SIZE => $this->remoteUsedSize
        ];
    }

    /**
     * @return string
     */
    public function getZfsPath(): string
    {
        return $this->zfsPath;
    }

    /**
     * @param string $zfsPath
     *
     * @return self
     */
    public function setZfsPath(string $zfsPath): self
    {
        $this->zfsPath = $zfsPath;

        return $this;
    }

    /**
     * @return int[]|null
     */
    public function getOffsitePoints()
    {
        return $this->offsitePoints;
    }

    /**
     * @param int[]|null $offsitePoints
     *
     * @return self
     */
    public function setOffsitePoints($offsitePoints): self
    {
        $this->offsitePoints = $offsitePoints;

        return $this;
    }

    /**
     * @return int[]|null
     */
    public function getCriticalPoints()
    {
        return $this->criticalPoints;
    }

    /**
     * @param int[]|null $criticalPoints
     *
     * @return self
     */
    public function setCriticalPoints($criticalPoints): self
    {
        $this->criticalPoints = $criticalPoints;

        return $this;
    }

    /**
     * @return int[]|null
     */
    public function getRemoteReplicatingPoints()
    {
        return $this->remoteReplicatingPoints;
    }

    /**
     * @param int[]|null $remoteReplicatingPoints
     *
     * @return self
     */
    public function setRemoteReplicatingPoints($remoteReplicatingPoints): self
    {
        $this->remoteReplicatingPoints = $remoteReplicatingPoints;

        return $this;
    }

    /**
     * @return int[]|null
     */
    public function getQueuedPoints()
    {
        return $this->queuedPoints;
    }

    /**
     * @param int[]|null $queuedPoints
     *
     * @return self
     */
    public function setQueuedPoints($queuedPoints): self
    {
        $this->queuedPoints = $queuedPoints;

        return $this;
    }

    /**
     * @return int|null
     */
    public function getRemoteUsedSize()
    {
        return $this->remoteUsedSize;
    }

    /**
     * @param int|null $remoteUsedSize
     *
     * @return self
     */
    public function setRemoteUsedSize($remoteUsedSize): self
    {
        $this->remoteUsedSize = $remoteUsedSize;

        return $this;
    }

    /**
     * @return int
     */
    public function getUpdated(): int
    {
        return $this->updated;
    }

    /**
     * @param int $updated
     *
     * @return self
     */
    public function setUpdated(int $updated): self
    {
        $this->updated = $updated;

        return $this;
    }
}
