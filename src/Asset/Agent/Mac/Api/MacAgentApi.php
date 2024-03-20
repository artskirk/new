<?php

namespace Datto\Asset\Agent\Mac\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\DattoAgentApi;

/**
 * Interfaces with the Mac Agent API
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @deprecated Mac agents are essentially not supported. Do not worry about maintaining code in here. It is ok to
 * completely remove the Mac code if it becomes an obstacle to future features/fixes, just ensure we have release notes
 * for the removal.
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class MacAgentApi extends DattoAgentApi
{
    /** Default mac agent port */
    const AGENT_PORT = 25569;

    public function getPlatform(): AgentPlatform
    {
        return AgentPlatform::DATTO_MAC_AGENT();
    }

    /**
     * @inheritDoc
     */
    public function needsOsUpdate(): bool
    {
        return false;
    }

    public function isAgentVersionSupported(): bool
    {
        return true;
    }
}
