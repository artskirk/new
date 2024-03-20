<?php

namespace Datto\Asset\Agent\Log;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentPlatform;
use Exception;

/**
 * Creates a Log Retriever based on the agent type.
 *
 * @author John Roland <jroland@datto.com>
 */
class RetrieverFactory
{
    /**
     * Creates a Log Retriever based on the agent type.
     * @param Agent $agent
     * @return Retriever
     */
    public function create(Agent $agent)
    {
        $retriever = null;
        switch ($agent->getPlatform()) {
            case AgentPlatform::SHADOWSNAP():
                $retriever = new ShadowProtectLogRetriever($agent);
                break;
            case AgentPlatform::DATTO_WINDOWS_AGENT():
            case AgentPlatform::DATTO_LINUX_AGENT():
            case AgentPlatform::DATTO_MAC_AGENT():
                $retriever = new DattoAgentLogRetriever($agent);
                break;
            case AgentPlatform::AGENTLESS():
            case AgentPlatform::AGENTLESS_GENERIC():
                /** @var AgentlessSystem $agent */
                $retriever = new AgentlessLogRetriever($agent);
                break;
            default:
                throw new Exception('Cannot retrieve logs for agent ' . $agent->getKeyName());
        }
        return $retriever;
    }
}
