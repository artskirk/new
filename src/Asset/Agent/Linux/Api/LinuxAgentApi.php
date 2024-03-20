<?php

namespace Datto\Asset\Agent\Linux\Api;

use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Api\DattoAgentApi;

/**
 * Interfaces with the Linux Agent API
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LinuxAgentApi extends DattoAgentApi
{
    /** Default linux agent port */
    const AGENT_PORT = 25567;

    public function getPlatform(): AgentPlatform
    {
        return AgentPlatform::DATTO_LINUX_AGENT();
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
