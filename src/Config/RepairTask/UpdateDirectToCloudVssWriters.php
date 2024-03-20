<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Windows\WindowsAgent;
use Datto\Asset\AssetType;
use Datto\Config\DeviceState;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Update any existing DTC agents with the DFS VSS writer exclusion. This is a one time task that is run on boot.
 */
class UpdateDirectToCloudVssWriters implements ConfigRepairTaskInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private DeviceState $deviceState;
    private AgentService $agentService;

    public function __construct(DeviceState $deviceState, AgentService $agentService)
    {
        $this->deviceState = $deviceState;
        $this->agentService = $agentService;
    }

    public function run(): bool
    {
        // We only need to run this task once (assuming it's successful).
        if ($this->deviceState->has(DeviceState::DTC_VSS_WRITERS_UPDATED)) {
            return false;
        }

        try {
            $this->updateDtcVssWriters();
        } catch (\Exception $e) {
            $this->logger->error('UDV0002 Failed to exclude VSS writers', [
                'exception' => $e
            ]);
        }


        $this->deviceState->touch(DeviceState::DTC_VSS_WRITERS_UPDATED);

        return true;
    }

    private function updateDtcVssWriters()
    {
        $agents = $this->agentService->getAll(AssetType::WINDOWS_AGENT);

        foreach ($agents as $agent) {
            $isApplicable = $agent->isDirectToCloudAgent();

            if ($isApplicable) {
                $this->logger->info('UDV0001 Excluding DFS for agents as a one-time action', [
                    'assetKey' => $agent->getKeyName()
                ]);

                /** @var WindowsAgent $agent */
                $agent->getVssWriterSettings()->excludeDfsWriter();

                $this->agentService->save($agent);
            }
        }
    }
}
