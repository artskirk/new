<?php

namespace Datto\Backup;

use Datto\Config\DeviceState;
use Datto\Backup\AgentBackupErrorResumableState;

/**
 * Methods for controlling our backup state file
 */
class ResumableBackupStateService
{
    /** @var DeviceState */
    private DeviceState $deviceState;

    public function __construct(
        DeviceState $deviceState
    ) {
        $this->deviceState = $deviceState;
    }

    /**
     * @return AgentBackupErrorResumableState[] indexed by agent UUID
     */
    public function getResumableBackupStates(): array
    {
        if ($this->deviceState->has(DeviceState::RESUMABLE_BACKUP_STATE)) {
            $agentUuidResumableBackupAttemptsArr = json_decode($this->deviceState->getRaw(DeviceState::RESUMABLE_BACKUP_STATE, null), true);
            $agentUuidResumableBackupAttempts = AgentBackupErrorResumableState::hydrateArray($agentUuidResumableBackupAttemptsArr);
        } else {
            $agentUuidResumableBackupAttempts = array();
        }
        return $agentUuidResumableBackupAttempts;
    }

    /**
     * @param AgentBackupErrorResumableState $agentUuidResumableBackupAttempts
     */
    public function saveResumableBackupFailureStateForAgent(AgentBackupErrorResumableState $agentUuidResumableBackupAttempts): void
    {
        $resumableBackupAttempts = $this->getResumableBackupStates();
        $resumableBackupAttempts[$agentUuidResumableBackupAttempts->getAgentUuid()] = $agentUuidResumableBackupAttempts;
        $this->deviceState->setRaw(DeviceState::RESUMABLE_BACKUP_STATE, json_encode($resumableBackupAttempts));
    }

    /**
     * @param string $agentUuid
     * @return AgentBackupErrorResumableState
     */
    public function getResumableBackupFailureState(string $agentUuid): AgentBackupErrorResumableState
    {
        $resumableBackupAttempts = $this->getResumableBackupStates();
        
        if (array_key_exists($agentUuid, $resumableBackupAttempts)) {
            return $resumableBackupAttempts[$agentUuid];
        }
        
        return new AgentBackupErrorResumableState($agentUuid, 0, null);
    }

    /**
     * @param string $agentUuid
     */
    public function resetResumableBackupFailureStateForAgent(string $agentUuid): void
    {
        if ($this->deviceState->has(DeviceState::RESUMABLE_BACKUP_STATE)) {
            $resumableBackupAttempts = $this->getResumableBackupStates();
            if (array_key_exists($agentUuid, $resumableBackupAttempts)) {
                $resumableBackupAttempts[$agentUuid]->setRetries(0);
                $this->deviceState->setRaw(DeviceState::RESUMABLE_BACKUP_STATE, json_encode($resumableBackupAttempts));
            }
        }
    }
}
