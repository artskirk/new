<?php

namespace Datto\Service\Alert;

use Datto\AlertSchemas\ProtectedMachine;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Asset;
use Datto\Asset\AssetType;

class ProtectedMachineFactory
{
    private AgentService $agentService;

    public function __construct(AgentService $agentService)
    {
        $this->agentService = $agentService;
    }

    public function fromAsset(Asset $asset): ProtectedMachine
    {
        $type = $this->determineType($asset);

        return $type === ProtectedMachine::TYPE_AGENT
            ? $this->fromAgent($this->agentService->get($asset->getKeyName()))
            : new ProtectedMachine($asset->getName(), $asset->getKeyName(), $type);
    }

    public function fromAgent(Agent $agent): ProtectedMachine
    {
        return new ProtectedMachine($agent->getDisplayName(), $agent->getKeyName(), ProtectedMachine::TYPE_AGENT);
    }

    private function determineType(Asset $asset): string
    {
        if ($asset->isType(AssetType::AGENT)) {
            return ProtectedMachine::TYPE_AGENT;
        } elseif ($asset->isType(AssetType::SHARE)) {
            return ProtectedMachine::TYPE_SHARE;
        } else {
            throw new \InvalidArgumentException(
                'Could not create ProtectedMachine from unsupported asset type: ' . $asset->getType()
            );
        }
    }
}
