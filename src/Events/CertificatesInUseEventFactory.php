<?php

namespace Datto\Events;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Datto\Config\AgentStateFactory;
use Datto\Events\CertificatesInUse\CertificatesInUseData;
use Datto\Events\CertificatesInUse\CertificatesInUseEventContext;
use Datto\Events\CertificatesInUse\AgentCertificateData;
use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Factory class to create CertificatesInUse events from the data that is relevant to those Events
 *
 */
class CertificatesInUseEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const DEVICE_CERTIFICATE_EVENT_NAME = 'device.agents.certificates.used';

    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var AgentStateFactory */
    protected $agentStateFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param CommonEventNodeFactory $nodeFactory
     * @param DateTimeService $dateTimeService
     * @param AgentStateFactory $agentStateFactory
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        CommonEventNodeFactory $nodeFactory,
        DateTimeService $dateTimeService,
        AgentStateFactory $agentStateFactory,
        DeviceLoggerInterface $logger
    ) {
        $this->dateTimeService = $dateTimeService;
        $this->nodeFactory = $nodeFactory;
        $this->agentStateFactory = $agentStateFactory;
        $this->logger = $logger;
    }

    public function create(string $trustedRootCertificateHash = null, Agent $agent = null): Event
    {
        $eventSuccess = false;
        $eventMessage = null;
        $exceptionMessage = null;

        if (!is_null($trustedRootCertificateHash)) {
            $eventMessage = 'Trusted root certificate retrieved';
            $eventSuccess = true;
        } else {
            $eventMessage = 'Current trusted root certificate hash unavailable';
            $this->logger->error('CIU0001 ' . $eventMessage);
        }

        if ($agent) {
            $agentCertificateData = $this->getAgentCertificateData($agent);
        }

        $data = new CertificatesInUseData(
            $this->nodeFactory->createPlatformData(),
            $trustedRootCertificateHash,
            $agentCertificateData ?? null
        );
        $context = new CertificatesInUseEventContext(
            $eventSuccess,
            $eventMessage,
            $exceptionMessage
        );
        return new Event(
            self::IRIS_SOURCE_NAME,
            self::DEVICE_CERTIFICATE_EVENT_NAME,
            $data,
            $context,
            'CIU-' . $this->dateTimeService->getTime(),
            $this->nodeFactory->getResellerId(),
            $this->nodeFactory->getDeviceId()
        );
    }

    private function getAgentCertificateData(Agent $agent): AgentCertificateData
    {
        $agentState = $this->agentStateFactory->create($agent->getKeyName());
        $agentTrustedRootCertificateHash = $agentState->get(CertificateUpdateService::TRUSTED_ROOT_HASH_KEY, 'Not found');

        return new AgentCertificateData(
            $this->nodeFactory->createAssetData($agent->getKeyName()),
            $agentTrustedRootCertificateHash
        );
    }
}
