<?php

namespace Datto\Asset\Agent\Api;

use Datto\Asset\Agent\Certificate\CertificateSet;
use Datto\Asset\Agent\Certificate\CertificateSetStore;
use Datto\Asset\Agent\PairingDeniedException;
use Datto\Asset\Agent\PairingFailedException;
use Datto\Cloud\JsonRpcClient;
use Datto\Util\RetryHandler;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base class to interface with all Agent APIs.
 *
 * This class should not rely on Agent or AgentConfig because the api is usable before pairing.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
abstract class BaseAgentApi implements AgentApi
{
    const URL_FORMAT = "https://%s:%d/";

    /** Placeholder agent port. This should be overridden in derived classes */
    const AGENT_PORT = 0;

    /** @var string */
    protected $agentFqdn;

    /** @var AgentRequest */
    protected $agentRequest;

    /** @var JsonRpcClient */
    protected $cloudClient;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var string */
    protected $url;

    /** @var CertificateSetStore */
    protected $certificateSetStore;

    /** @var RetryHandler */
    protected $retryHandler;

    public function __construct(
        string $agentFqdn,
        DeviceLoggerInterface $logger,
        AgentRequest $agentRequest = null,
        JsonRpcClient $cloudClient = null,
        CertificateSetStore $certificateSetStore = null,
        RetryHandler $retryHandler = null
    ) {
        $this->agentFqdn = $agentFqdn;
        $this->url = sprintf(static::URL_FORMAT, $agentFqdn, static::AGENT_PORT);

        $this->logger = $logger;
        $this->agentRequest = $agentRequest ?: new AgentRequest($this->url, $this->logger);
        $this->cloudClient = $cloudClient ?: new JsonRpcClient();
        $this->certificateSetStore = $certificateSetStore ?: new CertificateSetStore();
        $this->retryHandler = $retryHandler ?: new RetryHandler($logger);
    }

    /**
     * Returns the agent request object to make manual endpoint calls
     *
     * @return AgentRequest
     */
    public function getAgentRequest(): AgentRequest
    {
        return $this->agentRequest;
    }

    /**
     * Request a pairing ticket from device web to allow switching secure pairing agents from one device to another
     *
     * @param string $oldDeviceId
     * @return array Pairing ticket
     */
    protected function requestPairingTicket(string $oldDeviceId): array
    {
        if (empty($oldDeviceId)) {
            throw new Exception('Failed re-pairing, agent did not send old device ID');
        }

        $this->logger->info('BAA5010 Agent requested pairing ticket', ['oldDeviceId' => $oldDeviceId]);

        try {
            $issueParameters = ['oldDeviceId' => $oldDeviceId];
            $response = $this->cloudClient->queryWithId('v1/device/asset/agent/pair', $issueParameters);
            $this->logger->debug('BAA5019 Pairing ticket response from device-web: ' . json_encode($response));
        } catch (Exception $e) {
            $this->logger->error('BAA5017 Pairing call to device-web failed', ['exception' => $e]);
            throw new PairingFailedException();
        }

        if (!isset($response['success']) || empty($response['ticket']) || !is_array($response['ticket'])) {
            $this->logger->error('BAA5011 Error re-pairing, device-web pair response invalid', [
                'oldDeviceId' => $oldDeviceId
            ]);
            throw new PairingFailedException();
        }

        if (!$response['success']) {
            $message = $response['message'] ?? 'error message not found';
            $this->logger->error('BAA5012 Device-web denied re-pairing', [
                'oldDeviceId' => $oldDeviceId,
                'message' => $message
            ]);
            throw new PairingDeniedException();
        }

        $this->logger->info('BAA5013 Received pairing ticket from device-web');
        return $response['ticket'];
    }

    /**
     * @inheritDoc
     */
    public function getLatestWorkingCert(): CertificateSet
    {
        try {
            $response = $this->retryHandler->executeAllowRetry(
                function () {
                    // Call 'host', but get the raw result back, so we get all the info we need
                    $response = $this->agentRequest->get('host', [], false, false, true);
                    if ($response['errorCode'] !== 0) {
                        throw new Exception('Did not successfully connect to the agent!');
                    }
                    return $response;
                },
                AgentApi::RETRIES,
                AgentApi::RETRY_WAIT_TIME_SECONDS,
                $quiet = true
            );
        } catch (Exception $e) {
            // unwrap retryHandler exception to get to real exception if there is one
            throw $e->getPrevious() ?: $e;
        }

        $certificateSetUsed = $response[AgentRequest::CERTIFICATE_SET_USED];
        if ($certificateSetUsed instanceof CertificateSet) {
            return $certificateSetUsed;
        }

        throw new Exception('This agent does not use certs!');
    }
}
