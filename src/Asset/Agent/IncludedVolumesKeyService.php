<?php

namespace Datto\Asset\Agent;

use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

class IncludedVolumesKeyService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const INCLUDED_VOLUMES_KEY = 'include';

    private AgentConfigFactory $agentConfigFactory;
    private VolumesCollector $volumesCollector;
    private VolumesNormalizer $volumesNormalizer;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        VolumesCollector $volumesCollector,
        VolumesNormalizer $volumesNormalizer
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->volumesCollector = $volumesCollector;
        $this->volumesNormalizer = $volumesNormalizer;
    }

    public function loadFromKey(string $agentKey): IncludedVolumesSettings
    {
        $rawIncludeKey = $this->readKeyFileContents($agentKey);
        if (empty($rawIncludeKey)) {
            // UVM and any agent when pairing have empty include keys, and this is perfectly valid, return empty object
            return new IncludedVolumesSettings([]);
        }
        $rawAgentInfoKey = $this->agentConfigFactory->create($agentKey)->getRaw('agentInfo');
        $includedVolumesSettings = $this->loadFromKeyContents($agentKey, $rawAgentInfoKey, $rawIncludeKey);
        // If old style key is read, save it to file in new json format for guid-based backup continuity on mp change
        if ($this->isKeyInOldFormat($rawIncludeKey)) {
            $this->saveToKey($agentKey, $includedVolumesSettings);
        }
        return $includedVolumesSettings;
    }

    public function loadFromKeyContents(
        string $agentKey,
        string $agentInfoKeyContents,
        string $includeKeyContents
    ): IncludedVolumesSettings {
        $this->logger->setAssetContext($agentKey);

        $guidArray = json_decode($includeKeyContents);
        if (!is_array($guidArray)) {
            $guidArray = [];
            $agentConfig = $this->agentConfigFactory->create($agentKey);
            $volumes =
                $this->volumesCollector->collectVolumesFromAssocArray(
                    $this->volumesNormalizer->normalizeVolumesArrayFromAgentInfo(
                        $agentInfoKeyContents,
                        $agentConfig->isShadowsnap(),
                        new IncludedVolumesSettings([])
                    )
                )->getArrayCopy();
            $includedMountpoints = explode("\n", trim($includeKeyContents));
            foreach ($includedMountpoints as $mountpoint) {
                $foundGuid = false;
                foreach ($volumes as $volume) {
                    if ($volume->getMountpoint() === $mountpoint || $volume->getLabel() === $mountpoint) {
                        $guidArray[] = $volume->getGuid();
                        $foundGuid = true;
                        break;
                    }
                }
                if (!$foundGuid) {
                    $this->logger->warning(
                        'IVK0001 Missing included volume while converting include keyfile',
                        ['agentKey' => $agentKey, 'mountpointString' => $mountpoint]
                    );
                }
            }
        }
        return new IncludedVolumesSettings($guidArray);
    }

    public function saveToKey(string $agentKey, IncludedVolumesSettings $includedVolumeSettings)
    {
        $jsonEncoded = json_encode($includedVolumeSettings->getIncludedList());
        if ($this->hasKeyToLoad($agentKey) === false ||
            $jsonEncoded !== $this->readKeyFileContents($agentKey)
        ) {
            $this->agentConfigFactory
                ->create($agentKey)
                ->setRaw(self::INCLUDED_VOLUMES_KEY, $jsonEncoded);
        }
    }

    private function hasKeyToLoad(string $agentKey): bool
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        return $agentConfig->has(self::INCLUDED_VOLUMES_KEY);
    }

    private function isKeyInOldFormat(string $includeKeyContents): bool
    {
        $guidArray = json_decode($includeKeyContents);
        $isJson = is_array($guidArray) && !empty($guidArray);
        $includeKeyExploded = explode("\n", trim($includeKeyContents));
        $isKeyInOldFormat = !$isJson && is_array($includeKeyExploded) && !empty($includeKeyExploded);
        return $isKeyInOldFormat;
    }

    private function readKeyFileContents(string $agentKey): string
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        return $agentConfig->getRaw(self::INCLUDED_VOLUMES_KEY, '');
    }
}
