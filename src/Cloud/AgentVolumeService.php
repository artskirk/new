<?php

namespace Datto\Cloud;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;

/**
 * Update the cloud with the latest agent volume information
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 */
class AgentVolumeService
{
    const ASSET_TYPE_AGENT = 'siris';
    const ASSET_TYPE_RESCUE = 'rescue';

    /** @var JsonRpcClient */
    private $client;

    /** @var AgentService */
    private $agentService;

    /**
     * @param JsonRpcClient|null $client
     * @param AgentService|null $agentService
     */
    public function __construct(
        JsonRpcClient $client = null,
        AgentService $agentService = null
    ) {
        $this->client = $client ?: new JsonRpcClient();
        $this->agentService = $agentService ?: new AgentService();
    }

    /**
     * Updates the cloud with the latest volumes an agent has
     *
     * @param string $agentName
     * @return array|string
     */
    public function update($agentName)
    {
        $agent = $this->agentService->get($agentName);
        $agentVolumes = $agent->getVolumes();
        $volumes = array();
        foreach ($agentVolumes as $agentVolume) {
            $volume = array(
                'name' => $agentVolume->getMountpoint(),
                'status' => $agentVolume->isIncluded(),
                'totalSpace' => $agentVolume->getSpaceTotal(),
                'usedSpace' => $agentVolume->getSpaceUsed(),
                'filesystem' => $agentVolume->getFilesystem(),
                'guid' => $agentVolume->getGuid(),
                'osVolume' => $agentVolume->isOsVolume()
            );
            array_push($volumes, $volume);
        }

        $params = array(
            'assetKeyName' => $agentName,
            'assetType' => $agent->isRescueAgent() ? self::ASSET_TYPE_RESCUE : self::ASSET_TYPE_AGENT,
            'volumes' => $volumes
        );

        return $this->client->queryWithId('v1/device/asset/updateVolumes', $params);
    }
}
