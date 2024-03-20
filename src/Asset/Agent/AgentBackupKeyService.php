<?php

namespace Datto\Asset\Agent;

use Datto\Config\AgentConfigFactory;

/**
 * This class is responsible for setting several legacy (but still used) backup keyfiles
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentBackupKeyService
{
    const FORCE_FULL = 'forceFull';
    const HOST_OVERRIDE = 'hostOverride';

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /**
     * @param AgentConfigFactory $agentConfigFactory
     */
    public function __construct(AgentConfigFactory $agentConfigFactory)
    {
        $this->agentConfigFactory = $agentConfigFactory;
    }

    /**
     * Sets the forceFull key flag for an agent
     * @param Agent $agent
     */
    public function setForceFullKey(Agent $agent): void
    {
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        $agentConfig->set(self::FORCE_FULL, 1);
    }

    /**
     * Sets the hostOverride key for an agent
     * @param Agent $agent
     * @param string $hostOverride
     */
    public function setHostOverrideKey(Agent $agent, string $hostOverride = ''): void
    {
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        $agentConfig->set(self::HOST_OVERRIDE, $hostOverride);
    }
}
