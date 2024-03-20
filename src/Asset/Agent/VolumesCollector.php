<?php

namespace Datto\Asset\Agent;

/** Collect Volume Objects */
class VolumesCollector
{
    private VolumesNormalizer $normalizer;

    public function __construct(VolumesNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    public function collectVolumesFromAssocArray(array $volumes): Volumes
    {
        $collectedVolumes = new Volumes([]);
        foreach ($volumes as $volume) {
            $collectedVolumes->addVolume($this->collectVolumeObjectFromArray($volume));
        }
        return $collectedVolumes;
    }

    private function collectVolumeObjectFromArray(array $volume): Volume
    {
        $normalizedVolume = $this->normalizer->normalizeCommonVolumeAttributes($volume);

        return new Volume(
            $normalizedVolume['hiddenSectors'],
            $normalizedVolume['mountpoints'],
            $normalizedVolume['partScheme'],
            $normalizedVolume['spaceTotal'],
            $normalizedVolume['spaceFree'],
            $normalizedVolume['used'],
            $normalizedVolume['sectorSize'],
            $normalizedVolume['guid'],
            $normalizedVolume['diskUuid'],
            $normalizedVolume['OSVolume'],
            $normalizedVolume['clusterSize'],
            $normalizedVolume['realPartScheme'],
            $normalizedVolume['sysVolume'],
            $normalizedVolume['serialNumber'],
            $normalizedVolume['volumeType'],
            $normalizedVolume['label'],
            $normalizedVolume['removable'],
            $normalizedVolume['filesystem'],
            $normalizedVolume['blockDevice'],
            $normalizedVolume['included'],
            $normalizedVolume['mountpointsArray']
        );
    }
}
