<?php

namespace Datto\Service\AssetManagement\Repair;

use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentDataUpdateService;
use Datto\Asset\Agent\AgentNotPairedException;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\PairingDeniedException;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Asset\AssetRemovalService;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Config\AgentShmConfigFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Resource\Sleep;
use Datto\Service\AssetManagement\Create\Stages\PairAgent;
use Datto\Util\RetryHandler;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockException;
use Datto\Utility\File\LockFactory;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Main entrance service for agent repair operations.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class RepairAgent implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private AgentConfigFactory $agentConfigFactory;
    private FeatureService $featureService;
    private AgentConnectivityService $agentConnectivityService;
    private AgentApiFactory $agentApiFactory;
    private AgentPlatformChange $agentPlatformChange;
    private AgentDataUpdateService $agentDataUpdateService;
    private AgentShmConfigFactory $agentShmConfigFactory;
    private Sleep $sleep;
    private LockFactory $lockFactory;
    private AgentService $agentService;
    private RetryHandler $retryHandler;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        FeatureService $featureService,
        AgentConnectivityService $agentConnectivityService,
        AgentApiFactory $agentApiFactory,
        AgentPlatformChange $agentPlatformChange,
        AgentDataUpdateService $agentDataUpdateService,
        AgentShmConfigFactory $agentShmConfigFactory,
        Sleep $sleep,
        LockFactory $lockFactory,
        AgentService $agentService,
        RetryHandler $retryHandler
    ) {
        $this->agentConfigFactory = $agentConfigFactory;
        $this->featureService = $featureService;
        $this->agentConnectivityService = $agentConnectivityService;
        $this->agentApiFactory = $agentApiFactory;
        $this->agentPlatformChange = $agentPlatformChange;
        $this->agentDataUpdateService = $agentDataUpdateService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->sleep = $sleep;
        $this->lockFactory = $lockFactory;
        $this->agentService = $agentService;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Repairs the agent communications.
     */
    public function repair(string $agentKeyName)
    {
        $this->logger->setAssetContext($agentKeyName);

        $this->logger->info('PAR0501 Starting repair process for Agent', ['agentKeyName' => $agentKeyName]);

        $agentConfig = $this->agentConfigFactory->create($agentKeyName);
        $this->featureService->assertSupported(FeatureService::FEATURE_AGENT_REPAIR);

        // If able to be obtained, this lock will automatically unlock when it goes out of scope
        $lock = $this->checkInProgressRepair($agentKeyName);

        $agent = $this->agentService->get($agentKeyName);
        $currentPlatform = $agent->getPlatform();
        if (!$currentPlatform->isAgentless()) {
            // Figure out what platform this agent is, based on which port is actually responding
            $currentPlatform = $this->agentConnectivityService->determineAgentPlatform(
                $agentConfig->getFullyQualifiedDomainName(),
                $agentKeyName
            );

            $agentApi = $this->agentApiFactory->create(
                $agentConfig->getFullyQualifiedDomainName(),
                $currentPlatform,
                $this->logger
            );
        } else {
            // Agentless requires more information to create an API, and there's no possibility of the
            // platform changing, so create the agent API from the agent object
            $agentApi = $this->agentApiFactory->createFromAgent($agent);
        }

        if ($currentPlatform === AgentPlatform::SHADOWSNAP()) {
            try {
                // retrieve the existing shadowsnap key contents, and restore them if repair fails
                $keyBackup = $agentConfig->getRaw('key');
                $this->repairShadowSnap($agentConfig, $agentApi);
            } catch (Throwable $t) {
                $this->logger->error('RAH1001 ShadowSnap agent repair failed, restoring previous key contents', ['exception' => $t]);
                if (isset($keyBackup)) {
                    $agentConfig->set('key', $keyBackup);
                }
            }
        } elseif (!$currentPlatform->isAgentless()) {
            $this->agentPlatformChange->runIfNeeded($agentKeyName, $currentPlatform);

            $agentApi->pairAgent($agentKeyName);
        }

        $this->updateAgentInfoWithRetry($agentKeyName, $agentApi);

        // Repair is complete, allow other repair operations on this agent to proceed
        $lock->unlock();
    }

    /**
     * Obtains an exclusive "in-progress" lock, if it's available.
     * This non-blocking lock is used by both the auto repair and manual repair
     * operations to prevent both from running at the same time.
     * This operation is guaranteed to be autonomous between processes.
     *
     * @param string $agentKeyName Name of the agent.
     * @return Lock if the lock is available and the repair can proceed
     */
    private function checkInProgressRepair(string $agentKeyName): Lock
    {
        $agentShmConfig = $this->agentShmConfigFactory->create($agentKeyName);
        $lockFile = $this->lockFactory->create($agentShmConfig->getKeyFilePath('repairInProgress'));
        if (!$lockFile->exclusive(false)) {
            throw new LockException("Unable to repair agent $agentKeyName while another repair attempt is already in progress");
        }

        return $lockFile;
    }

    private function repairShadowSnap(AgentConfig $agentConfig, AgentApi $agentApi)
    {
        $storedLicenseKey = unserialize($agentConfig->get('key', ''), ['allowed_classes' => false]);
        $storedSerialNumber = $storedLicenseKey['code'] ?? '';
        $agentKeyName = $agentConfig->getKeyName();
        /** @var ShadowSnapAgentApi $agentApi */
        $response = $this->retryHandler->executeAllowRetry(
            function () use ($agentApi, $agentKeyName, $storedSerialNumber) {
                try {
                    $response = $agentApi->repairAgent($agentKeyName, $storedSerialNumber);
                } catch (AgentNotPairedException $exception) {
                    $this->logger->error('RAH0001 Failed to repair ShadowSnap Agent pairing due to agent not paired', ['exception' => $exception]);
                    $response = $agentApi->pairAgent($agentKeyName);
                }

                return $response;
            },
            PairAgent::MAX_AGENT_PAIR_ATTEMPTS,
            PairAgent::DELAY_AFTER_PAIRING_FAILURE_SECONDS
        );

        $this->persistShadowSnapKeyInfo($agentConfig, $response);

        // Sleep to give the ShadowSnap agent webserver time to reboot, which it does after pairing
        $this->sleep->sleep(PairAgent::DELAY_AFTER_PAIRING_SECONDS);
    }

    /**
     * ShadowSnap might need a couple of tries to update the agent info
     * TODO: This is an exact match with PairAgent - consolidate into 1 class
     */
    private function updateAgentInfoWithRetry(string $agentKeyName, AgentApi $agentApi)
    {
        $this->retryHandler->executeAllowRetry(
            function () use ($agentKeyName, $agentApi) {
                $this->agentDataUpdateService->updateAgentInfo(
                    $agentKeyName,
                    $agentApi
                );
            },
            PairAgent::MAX_AGENT_DATA_WRITE_ATTEMPTS,
            2
        );
    }

    // TODO: This is an exact match with PairAgent - consolidate into 1 class
    private function persistShadowSnapKeyInfo(AgentConfig $agentConfig, array $response)
    {
        // We used to just directly serialize the response to the key file, but lets do some extra checking
        // to prevent https://www.owasp.org/index.php/PHP_Object_Injection
        if (!isset($response['code'], $response['dattoKey'], $response['message'], $response['success']) ||
            !is_string($response['code']) ||
            !is_string($response['dattoKey']) ||
            !is_string($response['message']) ||
            !is_int($response['success'])
        ) {
            throw new PairingDeniedException('Response from ShadowSnap pair request did not contain properly formatted information');
        }
        $agentConfig->set(
            'key',
            serialize([
                'code' => $response['code'],
                'dattoKey' => $response['dattoKey'],
                'message' => $response['message'],
                'success' => $response['success']
            ])
        );
    }
}
