<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Agent\AutomaticVolumeInclusion\AutomaticVolumeInclusionService;
use Datto\Asset\Agent\AutomaticVolumeInclusion\InclusionResult;
use Datto\Cloud\AgentVolumeService;
use Datto\DirectToCloud\Api\DirectToCloudAgentApi;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;

/**
 * Logic for DTC agent checkin.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class CheckinService
{
    private AgentService $agentService;
    private DateTimeService $dateTimeService;
    private Collector $collector;
    private AgentDataUpdateService $agentDataUpdateService;
    private AgentVolumeService $agentVolumeService;
    private AutomaticVolumeInclusionService $automaticVolumeInclusionService;

    public function __construct(
        AgentService $agentService,
        DateTimeService $dateTimeService,
        Collector $collector,
        AgentDataUpdateService $agentDataUpdateService,
        AgentVolumeService $agentVolumeService,
        AutomaticVolumeInclusionService $automaticVolumeInclusionService
    ) {
        $this->agentService = $agentService;
        $this->dateTimeService = $dateTimeService;
        $this->collector = $collector;
        $this->agentDataUpdateService = $agentDataUpdateService;
        $this->agentVolumeService = $agentVolumeService;
        $this->automaticVolumeInclusionService = $automaticVolumeInclusionService;
    }

    /**
     * Update the agent's last seen time, handle defaulting an agent volume
     * configuration if necessary, and optionally save host its information
     *
     * @param string $assetKey
     * @param array|null $metadata
     */
    public function checkin(string $assetKey, array $metadata = null): void
    {
        $agent = $this->agentService->get($assetKey);
        $now = $this->dateTimeService->getTime();
        $previousAgentVolumes = $agent->getVolumes();

        $agent->getLocal()->setLastCheckin($now);
        $this->agentService->save($agent);

        if (isset($metadata['hostInfo'])) {
            $agentApi = new DirectToCloudAgentApi($metadata['hostInfo']);

            $this->agentDataUpdateService->updateAgentInfo(
                $assetKey,
                $agentApi,
                AgentDataUpdateService::SKIP_ZFS_UPDATE
            );
        }

        // Fetch fresh agent after changes have been made
        $agent = $this->agentService->get($assetKey);

        $inclusionResult = $this->automaticVolumeInclusionService->process($agent, $previousAgentVolumes);

        $this->saveAndSyncIfNeeded($agent, $previousAgentVolumes, $inclusionResult);

        $this->collector->increment(Metrics::DTC_AGENT_CHECKIN, [
            'agent_version' => $agent->getDriver()->getAgentVersion()
        ]);
    }

    private function saveAndSyncIfNeeded(Agent $agent, Volumes $previousVolumes, InclusionResult $inclusionResult): void
    {
        $currentVolumes = $agent->getVolumes();

        $updateCloud = $inclusionResult->hasChanges()
            || $this->hasVolumeGuidChanges($previousVolumes, $currentVolumes)
            || $this->hasVolumeMountPointChanges($previousVolumes, $currentVolumes);

        if ($updateCloud) {
            $this->agentService->save($agent);
            $this->agentVolumeService->update($agent->getKeyName());
        }
    }

    private function hasVolumeGuidChanges(Volumes $previousVolumes, Volumes $currentVolumes): bool
    {
        $toGuid = function (Volume $volume): string {
            return $volume->getGuid();
        };

        $previousGuids = array_map($toGuid, $previousVolumes->getArrayCopy());
        $currentGuids = array_map($toGuid, $currentVolumes->getArrayCopy());

        $differentGuids = array_merge(
            array_diff($previousGuids, $currentGuids),
            array_diff($currentGuids, $previousGuids)
        );

        return !empty($differentGuids);
    }

    private function hasVolumeMountPointChanges(Volumes $previousVolumes, Volumes $currentVolumes): bool
    {
        $sortByGuid = function (Volume $a, Volume $b): int {
            return $a->getGuid() <=> $b->getGuid();
        };

        $toMountPoint = function (Volume $volume): array {
            return $volume->getMountpointsArray();
        };

        $previousVolumesArray = $previousVolumes->getArrayCopy();
        $currentVolumesArray = $currentVolumes->getArrayCopy();

        usort($previousVolumesArray, $sortByGuid);
        usort($currentVolumesArray, $sortByGuid);

        $previousMountPoints = array_map($toMountPoint, $previousVolumesArray);
        $currentMountPoints = array_map($toMountPoint, $currentVolumesArray);

        return $previousMountPoints !== $currentMountPoints;
    }
}
