<?php

namespace Datto\Config\RepairTask;

use Datto\Config\AgentConfigFactory;
use Datto\Config\DeviceState;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Throwable;

/**
 * Class FixYearlyRetentionConfig
 *
 * This class implements a function to update existing agent configs so that the retention
 * time is not prematurely pruned
 *
 * @author Peter Del Col <pdelcol@datto.com>
 */
class FixYearlyRetentionConfig implements ConfigRepairTaskInterface
{
    const OFFSITE_RETENTION_KEY = 'offsiteRetention';
    const LOCAL_RETENTION_KEY  = 'retention';

    private DeviceState $deviceState;
    private AgentConfigFactory $agentConfigFactory;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        DeviceState $deviceState
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->deviceState = $deviceState;
    }

    /**
     * Fix preconfigured retention files to change the maximum retention setting from 364 (8736) days to 365 (8760)
     *
     * @return true if the configuration file has changed
     */
    public function run(): bool
    {
        // We only need to run this task once (assuming it's successful).
        if ($this->deviceState->has(DeviceState::RETENTION_MAXIMUM_UPDATED)) {
            return false;
        }
        $hasUpdated = false;
        $hasUpdated |= $this->processRetentionKey(self::OFFSITE_RETENTION_KEY);
        $hasUpdated |= $this->processRetentionKey(self::LOCAL_RETENTION_KEY);
        $this->deviceState->touch(DeviceState::RETENTION_MAXIMUM_UPDATED);

        return $hasUpdated;
    }


    /**
     * Get all the configuration files on the device and pass it into the replace function
     *
     * @param string $retentionKey to be searched for
     * @return true if the configuration has changed
     */
    private function processRetentionKey(string $retentionKey): bool
    {
        $hasUpdated = false;
        $allAgentKeys = $this->agentConfigFactory->getAllKeyNames();
        foreach ($allAgentKeys as $agentKey) {
            $agentConfig = $this->agentConfigFactory->create($agentKey);
            $retentionTimes = $agentConfig->get($retentionKey);
            $newRetentionTimes = $this->replaceInvalidTimes($retentionTimes);
            if ($retentionTimes !== $newRetentionTimes) {
                if ($agentConfig->set($retentionKey, $newRetentionTimes)) {
                    $hasUpdated |= true;
                }
            }
        }
        return $hasUpdated;
    }

    /**
     * Replace invalid configuration times and update the setting if needed. Will return the same setting if not
     *
     * @param string $retentionTimes current configuration setting
     * @return string updated configuration setting
     */
    private function replaceInvalidTimes(string $retentionTimes): string
    {
        $configTimes = explode(':', $retentionTimes);
        if (!isset($configTimes[3])) {
            return $retentionTimes;
        } elseif ($configTimes[3] == 8736) {
            $configTimes[3] = 8760;
        } elseif ($configTimes[3] == 17472) {
            $configTimes[3] = 17520;
        } elseif ($configTimes[3] == 26208) {
            $configTimes[3] = 26280;
        }
        return implode(':', $configTimes);
    }
}
