<?php

namespace Datto\Config\RepairTask;

use Datto\Asset\Agent\RepairService;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Convert the autorepair agent config files to json, and move them to agent state
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class MoveAutoRepairFileToAgentStateJson implements ConfigRepairTaskInterface
{
    private DeviceLoggerInterface $logger;
    private AgentConfigFactory $agentConfigFactory;
    private AgentStateFactory $agentStateFactory;

    public function __construct(
        DeviceLoggerInterface $logger,
        AgentConfigFactory $agentConfigFactory,
        AgentStateFactory $agentStateFactory
    ) {
        $this->logger = $logger;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->agentStateFactory = $agentStateFactory;
    }

    public function run(): bool
    {
        $agentKeyNames = $this->agentConfigFactory->getAllKeyNamesWithKey(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);

        return $this->convertAndMove($agentKeyNames);
    }

    /**
     * @param string[] $agentKeyNames The names of the agents that need config files to be converted and moved
     * @return bool
     */
    private function convertAndMove(array $agentKeyNames): bool
    {
        $changesOccurred = false;
        foreach ($agentKeyNames as $agentKeyName) {
            $agentConfig = $this->agentConfigFactory->create($agentKeyName);
            $agentConfigKeyPath = $agentConfig->getKeyFilePath(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);
            $agentState = $this->agentStateFactory->create($agentKeyName);
            $agentStateKeyPath = $agentState->getKeyFilePath(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);

            $phpSerializedContents = $agentConfig->getRaw(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);
            $contents = unserialize($phpSerializedContents, ['allowed_classes' => false]);
            if ($this->autoRepairFileContentsInvalid($contents)) {
                $this->logger->warning('CFG0053 Autorepair file is not valid, removing', ['file' => $agentConfigKeyPath]);
                $changesOccurred |= $agentConfig->clear(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);
                continue;
            }

            $this->logger->info(
                'CFG0050 Writing agent config file to agent state as json',
                ['source' => $agentConfigKeyPath, 'destination' => $agentStateKeyPath]
            );
            $writeSuccessful = $agentState->setRaw(AgentState::KEY_AUTOREPAIR_RETRYCOUNT, json_encode($contents));
            if ($writeSuccessful) {
                $agentConfig->clear(AgentState::KEY_AUTOREPAIR_RETRYCOUNT);
            } else {
                $this->logger->warning(
                    'CFG0051 Unable to write agent config file to agent state location',
                    ['source' => $agentConfigKeyPath, 'destination' => $agentStateKeyPath]
                );
            }
            $changesOccurred |= $writeSuccessful;
        }

        return $changesOccurred;
    }

    private function autoRepairFileContentsInvalid($contents): bool
    {
        return !is_array($contents) ||
            !array_key_exists('count', $contents) || !array_key_exists('timestamp', $contents) ||
            $contents['count'] === 0;
    }
}
