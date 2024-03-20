<?php

namespace Datto\Replication;

/**
 * Simple object model representing configurable settings for a replicated asset.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class AssetSettings
{
    /** @var bool */
    private $paused = false;

    /** @var int|null */
    private $pauseUntil;

    /** @var bool */
    private $pauseWhileMetered = false;

    /** @var int|null */
    private $maxBandwidthInBits;

    /** @var int|null */
    private $maxThrottledBandwidthInBits;

    /** @var array */
    private $includedVolumes = [];

    /**
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * @param bool $paused
     */
    public function setPaused(bool $paused)
    {
        $this->paused = $paused;
    }

    /**
     * @return int|null
     */
    public function getPauseUntil()
    {
        return $this->pauseUntil;
    }

    /**
     * @param int|null $pauseUntil
     */
    public function setPauseUntil($pauseUntil)
    {
        $this->pauseUntil = $pauseUntil;
    }

    /**
     * @return bool
     */
    public function isPauseWhileMetered(): bool
    {
        return $this->pauseWhileMetered;
    }

    /**
     * @param bool $pauseWhileMetered
     */
    public function setPauseWhileMetered(bool $pauseWhileMetered)
    {
        $this->pauseWhileMetered = $pauseWhileMetered;
    }

    /**
     * @return int|null
     */
    public function getMaxBandwidthInBits()
    {
        return $this->maxBandwidthInBits;
    }

    /**
     * @param int|null $maxBandwidthInBits
     */
    public function setMaxBandwidthInBits($maxBandwidthInBits)
    {
        $this->maxBandwidthInBits = $maxBandwidthInBits;
    }

    /**
     * @return int|null
     */
    public function getMaxThrottledBandwidthInBits()
    {
        return $this->maxThrottledBandwidthInBits;
    }

    /**
     * @param int|null $maxThrottledBandwidthInBits
     */
    public function setMaxThrottledBandwidthInBits($maxThrottledBandwidthInBits)
    {
        $this->maxThrottledBandwidthInBits = $maxThrottledBandwidthInBits;
    }

    /**
     * @return array
     */
    public function getIncludedVolumes()
    {
        return $this->includedVolumes;
    }

    /**
     * @param array $includedVolumes
     */
    public function setIncludedVolumes($includedVolumes)
    {
        $this->includedVolumes = $includedVolumes;
    }
}
