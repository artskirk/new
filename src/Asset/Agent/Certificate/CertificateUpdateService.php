<?php

namespace Datto\Asset\Agent\Certificate;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Api\AgentApi;
use Datto\Asset\Agent\Api\AgentApiFactory;
use Datto\Asset\Agent\Api\AgentCertificateException;
use Datto\Asset\Agent\Windows\Api\ShadowSnapAgentApi;
use Datto\Config\AgentState;
use Datto\Config\AgentStateFactory;
use Datto\Events\CertificatesInUseEventFactory;
use Datto\Events\EventService;
use Datto\Log\LoggerFactory;
use Datto\Utility\File\LockFactory;
use Datto\Utility\File\LockInfo;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

class CertificateUpdateService
{
    const TRUSTED_ROOT_HASH_KEY = 'trustedRootHash';
    const CERT_EXPIRATION_KEY = 'certificateExpiration';
    const OLD_CERT_EXPIRATION = 1588464000; // may 3rd 2020

    /** @var CertificateSetStore */
    private $certificateSetStore;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var AgentApiFactory */
    private $agentApiFactory;

    /** @var CertificatesInUseEventFactory */
    private $certificatesInUseEventFactory;

    /** @var EventService */
    private $eventService;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var LockFactory */
    private $lockFactory;

    /** @var AgentService */
    private $agentService;

    /** @var CertificateFactory */
    private $certificateFactory;

    public function __construct(
        CertificateSetStore $certificateSetStore,
        AgentApiFactory $agentApiFactory,
        AgentStateFactory $agentStateFactory,
        CertificatesInUseEventFactory $certificatesInUseEventFactory,
        EventService $eventService,
        LoggerFactory $loggerFactory,
        LockFactory $lockFactory,
        AgentService $agentService,
        CertificateFactory $certificateFactory
    ) {
        $this->certificateSetStore = $certificateSetStore;
        $this->agentApiFactory = $agentApiFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->certificatesInUseEventFactory = $certificatesInUseEventFactory;
        $this->eventService = $eventService;
        $this->loggerFactory = $loggerFactory;
        $this->logger = $loggerFactory->getDevice();
        $this->lockFactory = $lockFactory;
        $this->agentService = $agentService;
        $this->certificateFactory = $certificateFactory;
    }

    /**
     * Updates the certificates on any ShadowSnap agents in the list.
     * Also persists the hash of the latest certificate that the device was
     * able to use to communicate with the agent.
     * If this function is already running in another process, it will
     * return immediately.
     *
     * @param Agent[] $agents
     */
    public function updateAgentCertificates(array $agents)
    {
        $lock = $this->lockFactory->create(LockInfo::CERTIFICATE_UPDATE_SERVICE_LOCK_PATH);
        if (!$lock->exclusive(false)) {
            $this->logger->info('CRT7004 Attempt to inject certificates into agents while already in progress -- skipped');
            return;
        }

        $currentCertificates = $this->certificateSetStore->getCertificateSets();
        if (!isset($currentCertificates[0])) {
            $this->logger->error('CUS0001 No current certificate sets found on this device.');
            return;
        }

        foreach ($agents as $agent) {
            try {
                $this->injectCertAndTestAgent($agent, $currentCertificates[0]);
            } catch (Throwable $e) {
                $this->logger->error('CRT7002 Error attempting to inject certificate into agent', ['agentKey' => $agent->getKeyName(), 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Sends an event to elastic search (ELK) with the device information, the status of the current trusted
     * root certificate, its hash, and the hashes of all the device agent certificates.
     */
    public function sendCertificatesInUseEvent(): void
    {
        $certificateSets = $this->certificateSetStore->getCertificateSets();
        $trustedRootCertificateHash = isset($certificateSets[0]) ? $certificateSets[0]->getHash() : null;

        $atLeastOnAgentCertSent = false;

        // Only send up agents that communicate with the certificate
        $agents = $this->agentService->getAllActiveLocal();
        foreach ($agents as $agent) {
            if ($agent->communicatesWithoutCerts()) {
                continue;
            }

            $certificatesInUseEvent = $this->certificatesInUseEventFactory->create($trustedRootCertificateHash, $agent);
            $this->eventService->dispatch($certificatesInUseEvent, $this->logger);

            $atLeastOnAgentCertSent = true;
        }

        if (!$atLeastOnAgentCertSent) {
            // Send one for the device
            $certificatesInUseEvent = $this->certificatesInUseEventFactory->create($trustedRootCertificateHash);
            $this->eventService->dispatch($certificatesInUseEvent, $this->logger);
        }
    }

    /**
     * Gets the list of all agents, and for all agents that communicate using certs, finds their latest working cert
     * and persists it to the agent state.
     */
    public function testAllLatestWorkingAgentCertificates(): void
    {
        $agents = $this->agentService->getAllLocal();
        foreach ($agents as $agent) {
            try {
                $agentState = $this->agentStateFactory->create($agent->getKeyName());

                if ($agent->communicatesWithoutCerts()) {
                    if ($agent->getLocal()->isArchived()) {
                        $this->clearLatestWorkingCertificate($agentState);
                    }
                    continue;
                }

                $api = $this->agentApiFactory->createFromAgent($agent);
                if ($api instanceof ShadowSnapAgentApi && !$api->isNewApiVersion()) {
                    continue; // Shadowsnap agents older than 4.0.0 don't use these certificates to communicate
                }

                $this->updateLatestWorkingCertificate($api, $agentState);
            } catch (Throwable $e) {
                $logger = $this->loggerFactory->getAsset($agent->getKeyName());
                $logger->error('CRT7003 Error while testing SSL communication with agent', ['exception' => $e]);
            }
        }
    }

    /**
     * Inject the passed certificate into the agent if it's shadowsnap.
     * Test the agent to see whether it is using the $current cert and save the result.
     */
    private function injectCertAndTestAgent(Agent $agent, CertificateSet $current)
    {
        $agentState = $this->agentStateFactory->create($agent->getKeyName());
        $latestWorkingCertHash = $agentState->get(self::TRUSTED_ROOT_HASH_KEY);

        if ($agent->communicatesWithoutCerts() || $latestWorkingCertHash === $current->getHash()) {
            return;
        }

        $api = $this->agentApiFactory->createFromAgent($agent);

        if ($api instanceof ShadowSnapAgentApi) {
            if (!$api->isNewApiVersion()) {
                return; // Shadowsnap agents older than 4.0.0 don't use these certificates to communicate
            }
            $api->updateCertificate($current->getRootCertificatePath());
        }

        $this->updateLatestWorkingCertificate($api, $agentState);
    }

    /**
     * Figures out if a working cert hash was actually found, and persists it to agent state
     * This also saves the expiration time of the cert that was used
     */
    private function updateLatestWorkingCertificate(AgentApi $api, AgentState $agentState): void
    {
        $logger = $this->loggerFactory->getAsset($agentState->getKeyName());

        try {
            $certificateSet = $api->getLatestWorkingCert();

            $logger->info('CRT7000 Found working certificate', ['certificateHash' => $certificateSet->getHash()]);
            $latestWorkingCertHash = $agentState->get(self::TRUSTED_ROOT_HASH_KEY);
            // No need to write the same thing again
            if ($latestWorkingCertHash !== $certificateSet->getHash()) {
                $agentState->set(self::TRUSTED_ROOT_HASH_KEY, $certificateSet->getHash());
            }

            $certExpiration = $this->certificateFactory->createCertificate($certificateSet->getDeviceCertPath())->getValidTo();
            $agentState->set(self::CERT_EXPIRATION_KEY, $certExpiration);
        } catch (AgentCertificateException $e) {
            $previousHash = $agentState->get(self::TRUSTED_ROOT_HASH_KEY);
            if ($previousHash) {
                $logger->info("CRT7005 Agent had a certificate that worked previously but doesn't anymore. Either the certificate has expired, or the agent got a newer certificate that the device doesn't have yet.", ['previousHash' => $previousHash]);
            }

            $logger->error('CRT7001 No working certificate found for agent! Clearing trusted root hash key file.');
            $agentState->clear(self::TRUSTED_ROOT_HASH_KEY);

            // If we can't communicate and we've never saved an expiration, assume it's using the old cert and will expire on May 3rd 2020
            if (!$agentState->has(self::CERT_EXPIRATION_KEY)) {
                $agentState->set(self::CERT_EXPIRATION_KEY, self::OLD_CERT_EXPIRATION);
            }
        } catch (Throwable $e) {
            $logger->error("CRT7006 Unable to determine which certificate set works for agent because of non-SSL transport errors, not changing key file!", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Remove trusted root hash and cert expiration keys
     */
    private function clearLatestWorkingCertificate(AgentState $agentState): void
    {
        if ($agentState->has(self::TRUSTED_ROOT_HASH_KEY)) {
            $agentState->clear(self::TRUSTED_ROOT_HASH_KEY);
        }
        if ($agentState->has(self::CERT_EXPIRATION_KEY)) {
            $agentState->clear(self::CERT_EXPIRATION_KEY);
        }
    }
}
