<?php

namespace Datto\Asset\Agent;

use Datto\Asset\AssetType;

class VolumesNormalizer
{
    public function normalizeVolumesArrayFromAgentInfo(
        string $agentInfo,
        bool $isShadowsnap,
        IncludedVolumesSettings $includedVolumesSettings
    ): array {
        try {
            $agentInfoArray = unserialize($agentInfo) ?: [];
        } catch (\Throwable $t) {
            $agentInfoArray = [];
        }
        $volumes = (!empty($agentInfoArray['volumes']) && is_array($agentInfoArray['volumes'])) ?
            $agentInfoArray['volumes'] : [];
        if ($isShadowsnap) {
            return $this->normalizeShadowSnapVolumes($volumes, $includedVolumesSettings);
        }
        if (AssetType::isType(AssetType::AGENTLESS_GENERIC, $agentInfoArray)) {
            return $this->normalizeAgentlessGenericVolumes($volumes);
        }
        if (AssetType::isType(AssetType::AGENTLESS_LINUX, $agentInfoArray)) {
            return $this->normalizeLinuxVolumes($volumes, $includedVolumesSettings);
        }
        if (AssetType::isType(AssetType::AGENTLESS_WINDOWS, $agentInfoArray)) {
            return $this->normalizeAgentlessWindowsVolumes($volumes, $includedVolumesSettings);
        }
        if (AssetType::isType(AssetType::LINUX_AGENT, $agentInfoArray)) {
            return $this->normalizeLinuxVolumes($volumes, $includedVolumesSettings);
        }
        if (AssetType::isType(AssetType::MAC_AGENT, $agentInfoArray)) {
            return $this->normalizeMacVolumes($volumes, $includedVolumesSettings);
        }
        if (AssetType::isType(AssetType::WINDOWS_AGENT, $agentInfoArray)) {
            return $this->normalizeWindowsVolumes($volumes, $includedVolumesSettings);
        }

        return [];
    }

    /**
     * Normalizes the per-volume properties common across all Datto agent types.
     * @param array $volume
     * @return array
     */
    public function normalizeCommonVolumeAttributes(array $volume): array
    {
        $spaceFree = $volume['spaceFree'] ?? ($volume['spacefree'] ?? 0);
        $blockDevice = $volume['device'] ?? ($volume['blockDevice'] ?? '');
        $clusterSize = $volume['clusterSizeInBytes'] ?? ($volume['clusterSize'] ?? 0);
        $partScheme = $volume['partScheme'] ?? 'MBR';
        $realPartScheme = $volume['realPartScheme'] ?? $partScheme;
        return [
            'mountpoints' => $volume['mountpoints'],
            'mountpointsArray' => $volume['mountpointsArray'] ?? [$volume['mountpoints']] ?? [],
            'spaceTotal' => $volume['spaceTotal'],
            'capacity' => $volume['spaceTotal'],
            'spaceFree' => $spaceFree,
            'used' => $volume['spaceTotal'] - $spaceFree,
            'uuid' => $volume['guid'],
            'guid' => $volume['guid'],
            'diskUuid' => $volume['diskUuid'] ?? '',
            'sectorSize' => $volume['sectorSize'] ?? null,
            'blockDevice' => $blockDevice,
            'OSVolume' => (bool) $volume['OSVolume'],
            'sysVolume' => (bool) $volume['sysVolume'],
            'filesystem' => $volume['filesystem'],
            'hiddenSectors' => $volume['hiddenSectors'] ?? 0,
            'label' => $volume['label'] ?? '',
            'included' => $volume['included'] ?? false,
            'clusterSize' => $clusterSize,
            'volumeType' => $volume['volumeType'] ?? '',
            'removable' => $volume['removable'] ?? false,
            'partScheme' => $partScheme,
            'realPartScheme' => $realPartScheme,
            'serialNumber' => $volume['serialNumber'] ?? 0
        ];
    }

    public function normalizeWindowsVolumes(array $vols, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $result = [];
        foreach ($vols as $volume) {
            if (!isset($volume['mountpoints'][0]) || (isset($volume['readonly']) && $volume['readonly'])) {
                continue;
            }
            $volume['included'] = $includedVolumesSettings->isIncluded($volume['guid']);

            $volumeEntry = $this->normalizeCommonVolumeAttributes($volume);

            if (is_array($volume['mountpoints'])) {
                $volumeEntry['mountpointsArray'] = $volume['mountpoints'];
                $volumeEntry['mountpoints'] = $volume['mountpoints'][0];
            } elseif (is_string($volume['mountpoints'])) {
                // Only over-ride mountpointsArray if it does not exist or is not an array
                if (!isset($volume['mountpointsArray']) || !is_array($volumeEntry['mountpointsArray'])) {
                    $volumeEntry['mountpointsArray'] = [$volume['mountpoints']];
                }
            } elseif (!empty($volume['label'])) {
                $volumeEntry['mountpoints'] = $volume['label'];
                $volumeEntry['mountpointsArray'] = [$volume['label']];
            } else {
                // If no mountpoint or label, continue and exclude this volume
                continue;
            }

            $result[$volumeEntry['mountpoints']] = $volumeEntry;
        }

        return $result;
    }

    public function normalizeAgentlessWindowsVolumes(array $vols, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $result = [];
        foreach ($vols as $id => $volume) {
            if (!is_array($volume) || (isset($volume['readonly']) && $volume['readonly'])) {
                continue;
            }
            $volume['included'] = $includedVolumesSettings->isIncluded($volume['guid']);

            $volumeEntry = $this->normalizeCommonVolumeAttributes($volume);
            $volumeEntry['mountpointsArray'] = [$volumeEntry['mountpoints']];
            $result[$id] = $volumeEntry;
        }

        return $result;
    }

    public function normalizeLinuxVolumes(array $vols, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $result = array();
        foreach ($vols as $volume) {
            if (empty($volume['mountpoints']) || (isset($volume['readonly']) && $volume['readonly'])) {
                continue;
            }
            $volume['included'] = $includedVolumesSettings->isIncluded($volume['guid']);

            $volumeEntry = $this->normalizeCommonVolumeAttributes($volume);
            $volumeEntry['mountpointsArray'] = [$volume['mountpoints']];
            $result[$volume['mountpoints']] = $volumeEntry;
        }

        return $result;
    }

    /**
     * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
     * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release
     * notes for the removal.
     */
    public function normalizeMacVolumes(array $vols, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $result = array();
        foreach ($vols as $volume) {
            if (empty($volume['mountpoints']) || (isset($volume['readonly']) && $volume['readonly'])) {
                continue;
            }

            $volume['included'] = $includedVolumesSettings->isIncluded($volume['guid']);
            $volumeEntry = $this->normalizeCommonVolumeAttributes($volume);
            $volumeEntry['mountpointsArray'] = [$volume['mountpoints']];
            $volumeEntry['filesystem'] = 'hfsplus'; // We only support one mac filesystem
            $result[$volume['mountpoints']] = $volumeEntry;
        }

        return $result;
    }

    public function normalizeShadowSnapVolumes(array $volumes, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $result = [];
        foreach ($volumes as $id => $vol) {
            $vol['included'] = $includedVolumesSettings->isIncluded($vol['guid']);
            $vol['capacity'] = $vol['spaceTotal'];
            // Shadowsnap uses 'spacefree' while others use 'spaceFree'
            $vol['spaceFree'] = $vol['spacefree'];
            $vol['used'] = $vol['spaceTotal'] - $vol['spacefree'];
            $vol['mountpointsArray'] = [$vol['mountpoints']];
            $vol['clusterSize'] = $vol['clusterSizeInBytes'] ?? 0;
            // No good data for these from shadowsnap
            $vol['blockDevice'] = '';
            $vol['diskUuid'] = '';

            $result[$vol['mountpoints']] = $vol;
        }

        return $result;
    }

    public function normalizeAgentlessGenericVolumes(array $volumes): array
    {
        $result = array();
        foreach ($volumes as $volume) {
            if (empty($volume['mountpoints']) || (isset($volume['readonly']) && $volume['readonly'])) {
                continue;
            }
            $volume['included'] = true;

            $volumeEntry = $this->normalizeCommonVolumeAttributes($volume);
            $volumeEntry['mountpointsArray'] = [$volume['mountpoints']];
            $result[$volume['mountpoints']] = $volumeEntry;
        }

        return $result;
    }
}
