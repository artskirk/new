<?php

namespace Datto\Asset\Agent;

/**
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DiffMergeSettings
{
    /** @var bool */
    private $allVolumes;

    /** @var string[] */
    private $volumeGuids;

    /**
     * @param bool $allVolumes
     * @param string[] $volumeGuids
     */
    public function __construct(bool $allVolumes, array $volumeGuids)
    {
        $this->allVolumes = $allVolumes;
        $this->volumeGuids = $volumeGuids;
    }

    /**
     * @return bool
     */
    public function isAllVolumes(): bool
    {
        return $this->allVolumes;
    }

    /**
     * @return string[]
     */
    public function getVolumeGuids(): array
    {
        return $this->volumeGuids;
    }

    /**
     * @return bool
     */
    public function isAnyVolume(): bool
    {
        return $this->allVolumes || !empty($this->volumeGuids);
    }
}
