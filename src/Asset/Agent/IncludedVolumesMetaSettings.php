<?php

namespace Datto\Asset\Agent;

/**
 * Manages settings for the volume metadata of an agent.
 *
 * @author Peter Salu <psalu@datto.com>
 */
class IncludedVolumesMetaSettings
{
    /** @var VolumeMetadata[] */
    private $volumeMetadata;

    /**
     * @param VolumeMetadata[] $volumeMetadata
     */
    public function __construct(array $volumeMetadata)
    {
        $this->volumeMetadata = $volumeMetadata;
    }

    /**
     * @return VolumeMetadata[] indexed by volume guid
     */
    public function getVolumeMetadata()
    {
        return $this->volumeMetadata;
    }

    /**
     * @param VolumeMetadata $volume
     */
    public function add(VolumeMetadata $volume): void
    {
        if (!$this->isIncluded($volume)) {
            $this->volumeMetadata[$volume->getGuid()] = $volume;
        }
    }

    /**
     * @param VolumeMetadata $volume
     */
    public function remove(VolumeMetadata $volume)
    {
        if (!$this->isIncluded($volume)) {
            return;
        }

        unset($this->volumeMetadata[$volume->getGuid()]);
    }

    /**
     * @param VolumeMetadata $newVolume
     */
    public function replace(VolumeMetadata $newVolume): void
    {
        if ($this->isIncluded($newVolume)) {
            $this->volumeMetadata[$newVolume->getGuid()] = $newVolume;
        }
    }

    /**
     * @param VolumeMetadata $volume
     * @return bool
     */
    public function isIncluded(VolumeMetadata $volume)
    {
        return isset($this->volumeMetadata[$volume->getGuid()]) ||
               array_key_exists($volume->getGuid(), $this->volumeMetadata);
    }
}
