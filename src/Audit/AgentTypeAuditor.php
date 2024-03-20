<?php

namespace Datto\Audit;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\MachineIdService;
use Datto\Cloud\JsonRpcClient;
use Datto\Cloud\CloudErrorException;
use Datto\Log\DeviceLogger;
use Datto\Log\DeviceLoggerInterface;

/**
 * This class is responsible for reporting information about the agent to device-web for auditing purposes
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AgentTypeAuditor
{
    const V1_AUDIT_ENDPOINT = 'v1/audit/agentType/update';

    /**
     * @var AgentService
     */
    private $agentService;

    /**
     * @var MachineIdService
     */
    private $machineIdService;

    /**
     * @var JsonRpcClient
     */
    private $client;

    /**
     * @var DeviceLoggerInterface
     */
    private $logger;

    /**
     * @param AgentService $agentService
     * @param MachineIdService $machineIdService
     * @param JsonRpcClient $client
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AgentService $agentService,
        MachineIdService $machineIdService,
        JsonRpcClient $client,
        DeviceLoggerInterface $logger
    ) {
        $this->agentService = $agentService;
        $this->machineIdService = $machineIdService;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Audit all of the agents and report their information to device-web
     */
    public function reportAll(): void
    {
        $agents = $this->agentService->getAllActiveLocal();

        // batch all of our json-rpc notify calls
        $this->client->batch();

        $auditableAgents = 0;
        $auditSuccesses = 0;

        foreach ($agents as $agent) {
            $isPaused = $agent->getLocal()->isPaused();
            $isDtc = $agent->isDirectToCloudAgent();

            if (!$isPaused && !$isDtc) {
                $auditableAgents++;
                try {
                    $this->report($agent);
                    $auditSuccesses++;
                } catch (\Exception $e) {
                    $this->logException($agent, $e);
                }
            }
        }

        $this->logger->info('AUD0006 Audited applicable agents', ['auditSuccesses' => $auditSuccesses, 'auditableAgents' => $auditableAgents]);

        $this->client->send();
    }

    /**
     * Report a single agent to device-web
     *
     * @param Agent $agent
     */
    private function report(Agent $agent): void
    {
        $this->logger->setAssetContext($agent->getKeyName());
        $this->logger->info('AUD0001 Auditing agent');

        $agentType = $agent->getPlatform()->value();
        $machineId = $this->machineIdService->getMachineId($agent);

        $this->logger->info('AUD0002 found agent-type', ['agentType' => $agentType]);
        $this->logger->info('AUD0003 found machine-id', ['machineId' => $machineId]);

        $requestArguments = array(
            'agentType' => $agentType,
            'machineID' => $machineId
        );

        $this->logger->info('AUD0004 reporting information to device-web');
        $this->client->notifyWithId(self::V1_AUDIT_ENDPOINT, $requestArguments);

        $this->logger->removeFromGlobalContext(DeviceLogger::CONTEXT_ASSET);
    }

    private function logException(Agent $agent, \Exception $e): void
    {
        $errorInformation = array(
            'agent' => array(
                'name' => $agent->getName(),
                'hostname' => $agent->getHostname()
            ),
            'error-message' => $e->getMessage()
        );

        if ($e instanceof CloudErrorException) {
            $errorInformation['error-object'] = $e->getErrorObject();
        }

        $this->logger->error('AUD0005 Unable to audit agent type for agent', $errorInformation);
    }
}
