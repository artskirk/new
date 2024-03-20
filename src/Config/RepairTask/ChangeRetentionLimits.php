<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\OffsiteSettings;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Core\Configuration\ConfigRepairTaskInterface;

/**
 * Update retention limits
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class ChangeRetentionLimits implements ConfigRepairTaskInterface
{
    const RETENTION_LIMITS_KEY = 'offsiteRetentionLimits';

    /** AgentConfigFactory */
    private $agentConfigFactory;

    /**
     * @param AgentConfigFactory $agentConfigFactory
     */
    public function __construct(
        AgentConfigFactory $agentConfigFactory
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
    }

    /**
     * Update retentionLimits for all agents and shares
     *
     * @return true if at least one agent or share was updated, false otherwise
     *
     * @inheritdoc
     */
    public function run(): bool
    {
        $hasUpdated = false;
        $allAgentKeys = $this->agentConfigFactory->getAllKeyNames();

        foreach ($allAgentKeys as $agentKey) {
            $agentConfig = $this->agentConfigFactory->create($agentKey);
            $hasUpdated |= $this->update($agentConfig);
        }

        return $hasUpdated;
    }

    /**
     * @param string $retentionLimits
     *
     * @return string
     */
    private function getNewLimits(string $retentionLimits)
    {
        $newOnDemandLimit = OffsiteSettings::DEFAULT_ON_DEMAND_RETENTION_LIMIT;
        $newNightlyLimit = OffsiteSettings::DEFAULT_NIGHTLY_RETENTION_LIMIT;

        $currentLimits = explode(':', $retentionLimits);

        if (count($currentLimits) !== 2) {
            // corrupted limits, fix it
            return $newOnDemandLimit . ':' .$newNightlyLimit;
        }

        $currentOnDemandLimit = $currentLimits[0];
        $currentNightlyLimit = $currentLimits[1];

        $needsUpdate = $currentNightlyLimit < $newNightlyLimit;

        if ($needsUpdate) {
            // update nightly with new limit
            return $currentOnDemandLimit . ':' . $newNightlyLimit;
        }

        // leave as-is
        return $retentionLimits;
    }

    /**
     * @param AgentConfig $agentConfig
     *
     * @return bool
     */
    private function update(AgentConfig $agentConfig): bool
    {
        $retentionLimits = $agentConfig->get(self::RETENTION_LIMITS_KEY);
        $newRetentionLimits = $this->getNewLimits($retentionLimits);
        $canUpdate = $retentionLimits !== $newRetentionLimits;
        $hasUpdated = false;

        if ($canUpdate) {
            $hasUpdated = $agentConfig->set(self::RETENTION_LIMITS_KEY, $newRetentionLimits);
        }

        return $hasUpdated;
    }
}
