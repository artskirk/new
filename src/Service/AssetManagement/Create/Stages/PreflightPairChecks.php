<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Asset\Agent\AgentService;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentStateFactory;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Feature\FeatureService;
use Datto\License\AgentLimit;
use Datto\Replication\ReplicationService;
use Datto\Service\AssetManagement\Create\CreateAgentProgress;
use Exception;
use Throwable;

/**
 * Run any preflight checks to ensure we can pair this agent properly
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PreflightPairChecks extends AbstractCreateStage
{
    /** @var FeatureService */
    private $featureService;

    /** @var AgentService */
    private $agentService;

    /** @var EsxConnectionService */
    private $connectionService;

    /** @var ReplicationService */
    private $replicationService;

    /** @var AgentLimit */
    private $agentLimit;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    public function __construct(
        FeatureService $featureService,
        AgentService $agentService,
        EsxConnectionService $connectionService,
        ReplicationService $replicationService,
        AgentLimit $agentLimit,
        AgentStateFactory $agentStateFactory
    ) {
        $this->featureService = $featureService;
        $this->agentService = $agentService;
        $this->connectionService = $connectionService;
        $this->replicationService = $replicationService;
        $this->agentLimit = $agentLimit;
        $this->agentStateFactory = $agentStateFactory;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        if ($this->isPaired()) {
            $message = 'A system with this domain name has already been paired with this device.';
            $this->setErrorAndThrow(CreateAgentProgress::EXISTS, $message);
        }

        if ($this->context->isAgentless() && !$this->connectionService->exists($this->context->getConnectionName())) {
            $errorMessage = 'ESX connection ' . $this->context->getConnectionName() . ' does not exist';
            $this->setErrorAndThrow(CreateAgentProgress::NO_HYPERVISOR, $errorMessage);
        }

        if ($this->context->getAgentKeyToCopy()) {
            if ($this->agentService->exists($this->context->getAgentKeyToCopy())) {
                $agentToCopy = $this->agentService->get($this->context->getAgentKeyToCopy());
                $this->context->setOffsiteTarget($agentToCopy->getOffsiteTarget());
            } else {
                $message = 'The agent to copy settings from does not exist';
                $this->setErrorAndThrow(CreateAgentProgress::AGENT_TEMPLATE_DOES_NOT_EXIST, $message);
            }
        }

        $logger = $this->context->getLogger();
        $peerReplicationSupported = $this->featureService->isSupported(FeatureService::FEATURE_PEER_REPLICATION);
        $offsiteTarget = $this->context->getOffsiteTarget();

        if ($offsiteTarget === SpeedSync::TARGET_CLOUD) {
            $logger->info('CSS0010 Using datto cloud as replication target for agent');
        } elseif ($offsiteTarget === SpeedSync::TARGET_NO_OFFSITE) {
            $logger->info('CSS0011 No replication target set for agent');
        } elseif ($peerReplicationSupported && SpeedSync::isPeerReplicationTarget($offsiteTarget)) {
            $logger->info('CSS0012 Using device as replication target for agent', ['offsiteTarget' => $offsiteTarget]);
            $this->replicationService->assertDeviceReachable($offsiteTarget);
        } else {
            $p2pMessage = $peerReplicationSupported ? 'a device ID, ' : '';
            $message = 'Offsite Target must be either ' . $p2pMessage . SpeedSync::TARGET_CLOUD . ' or ' . SpeedSync::TARGET_NO_OFFSITE;
            $this->setErrorAndThrow(CreateAgentProgress::BAD_OFFSITE_TARGET, $message);
        }

        if ($this->context->needsEncryption() && !$this->featureService->isSupported(FeatureService::FEATURE_ENCRYPTED_BACKUPS)) {
            $this->setErrorAndThrow(CreateAgentProgress::NO_ENCRYPTION_SUPPORT, 'Agent encryption is not supported.');
        }

        try {
            $logger->debug('PAR0015 Reserving slot in the agent limit for ' . $this->context->getAgentKeyName());
            $this->agentLimit->reserveAgent($this->context->getAgentKeyName());
        } catch (Throwable $e) {
            $logger->error('PAR0012 Unable to reserve agent. Agent limit reached', ['exception' => $e]);
            $this->setErrorAndThrow(CreateAgentProgress::AGENT_LIMIT_REACHED, 'Agent limit has been reached');
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        $logger = $this->context->getLogger();
        try {
            $logger->debug('PAR0014 Releasing agent limit reservation');
            $this->agentLimit->releaseReservation($this->context->getAgentKeyName());
        } catch (Throwable $e) {
            $this->context->getLogger()->error('PAR0013 Failed to release agent reservation. To manually resolve, delete the /dev/shm/agentLimitSlots file.', ['exception' => $e]);
        }
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $this->cleanup();
    }

    private function isPaired(): bool
    {
        if ($this->context->isAgentless()) {
            return $this->agentService->isAgentlessSystemPaired($this->context->getMoRef(), $this->context->getConnectionName());
        }

        return $this->agentService->isDomainPaired($this->context->getDomainName());
    }

    private function setErrorAndThrow(string $state, string $message)
    {
        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();

        $createProgress->setState($state);
        $createProgress->setErrorMessage($message);
        $agentState->saveRecord($createProgress);

        $this->context->getLogger()->error('PHD1002 ' . $message);

        throw new Exception($message);
    }
}
