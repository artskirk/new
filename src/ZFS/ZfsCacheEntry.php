<?php
namespace Datto\ZFS;

use JsonSerializable;

/**
 * Contains the cache information for a particular dataset
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class ZfsCacheEntry implements JsonSerializable
{
    /** @var string */
    protected $zfsPath;

    /** @var int */
    protected $updated = 0;

    /** @var int[]|null */
    protected $usedSizes;

    /**
     * @param string $zfsPath
     * @param int $updated
     * @param int[] $usedSizes
     */
    public function __construct(string $zfsPath, int $updated = 0, array $usedSizes = null)
    {
        $this->zfsPath = $zfsPath;
        $this->updated = $updated;
        $this->usedSizes = $usedSizes;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            ZfsCache::UPDATED => $this->updated,
            ZfsCache::USED_SIZES => $this->usedSizes
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
    public function getUsedSizes()
    {
        return $this->usedSizes;
    }

    /**
     * @param int[] $usedSizes
     *
     * @return self
     */
    public function setUsedSizes(array $usedSizes)
    {
        $this->usedSizes = $usedSizes;

        return $this;
    }

    /**
     * @param int $snapshot
     * @param int $usedSize
     * @return $this
     */
    public function setUsedSize(int $snapshot, int $usedSize)
    {
        $this->usedSizes[$snapshot] = $usedSize;

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
