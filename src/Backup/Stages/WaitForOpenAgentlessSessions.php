<?php

namespace Datto\Backup\Stages;

use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\AppKernel;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\Agentless\Api\AgentlessProxyApi;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Util\RetryHandler;
use Exception;
use Throwable;

/**
 * Stage that waits for any open agentless sessions to complete before starting a new session for the backup process.
 * If one is hung or was killed unexpectedly, the timeout will be reached and it will be overridden with the new session
 * created.
 *
 * REFACTOR REQUIRED!
 *
 *
 * @package Datto\Backup\Stages
 */
class WaitForOpenAgentlessSessions extends BackupStage
{
    private const RETRIES = 5;
    private const WAIT_TIME = 60;

    private EsxConnectionService $esxConnectionService;
    private AgentlessSessionService $agentlessSessionService;
    private RetryHandler $retryHandler;

    public function __construct(
        EsxConnectionService    $esxConnectionService,
        AgentlessSessionService $agentlessSessionService,
        RetryHandler            $retryHandler
    ) {
        $this->esxConnectionService = $esxConnectionService;
        $this->agentlessSessionService = $agentlessSessionService;
        $this->retryHandler = $retryHandler;
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        $this->context->getLogger()->info('BAK0034 Waiting for any active agentless API sessions');
        /** @var AgentlessSystem $agent */
        $agent = $this->context->getAsset();

        $agentApi = $this->context->getAgentApi();
        if ($agentApi instanceof AgentlessProxyApi && $agentApi->isInitialized()) {
            $bootedInstance = AppKernel::getBootedInstance();
            if ($bootedInstance->isDevMode()) {
                throw new Exception('AgentlessProxyApi has been initialized before the WaitForOpenAgentlessSessions stage. This should not happen, otherwise this stage will waste 4 minutes clearing up an agentless session that will then be recreated.');
            }
        }
        $esxConnection = $this->esxConnectionService->get($agent->getEsxInfo()->getConnectionName());
        if (!$esxConnection || !($esxConnection instanceof EsxConnection)) {
            $msg = "ESX connection with name '{$agent->getEsxInfo()->getConnectionName()}' not found.";
            $this->context->getLogger()->error("BTF0002 " . $msg);
            throw new Exception($msg);
        }

        $host = $esxConnection->getPrimaryHost();
        $user = $esxConnection->getUser();
        $password = $esxConnection->getPassword();
        if (is_null($host) || is_null($user) || is_null($password)) {
            $connectionName = $agent->getEsxInfo()->getConnectionName();
            $this->context->getLogger()->error("BTF0003 ESX connection doesn't have host, user, or password", ['name' => $connectionName, 'host' => $host, 'user' => $user, 'password' => $password]);
            $msg = "ESX connection with name '{$connectionName}' doesn't have host (host: '$host'), user (user: '$user'), or password (password: '$password').";
            throw new Exception($msg);
        }

        $agentlessSessionId = $this->agentlessSessionService->generateAgentlessSessionId(
            $host,
            $user,
            $password,
            $agent->getEsxInfo()->getMoRef(),
            $agent->getKeyName()
        );

        try {
            $this->retryHandler->executeAllowRetry(
                function () use ($agentlessSessionId) {
                    // Wait for current session to be finished or die out.
                    $apiSessionRunning = $this->agentlessSessionService->isSessionRunning($agentlessSessionId);
                    if ($apiSessionRunning) {
                        $msg = 'An agentless API session for this asset is still running. ' .
                            'Waiting 60 seconds before next check.';
                        throw new Exception($msg);
                    } else {
                        return true;
                    }
                },
                self::RETRIES,
                self::WAIT_TIME
            );
        } catch (Throwable $e) {
            $this->context->getLogger()->debug("BAK0035 An agentless API session for this asset is still running after 5 attempts. ' 
                . 'Cleaning up running sessions.");
            $this->context->getAgentApi()->cleanup();
        } finally {
            // Now, wait for the lock to be released.
            $this->agentlessSessionService->waitUntilSessionIsReleased(
                $agentlessSessionId,
                $this->context->getLogger(),
                60 * 5
            );
            $this->context->getLogger()->debug("BAK0036 Agentless API ready to be initialized.");
        }
    }

    /**
     * @inheritDoc
     */
    public function cleanup(): void
    {
    }
}
