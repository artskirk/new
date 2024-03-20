<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Resource\DateTimeService;
use Datto\Metrics\Metrics;

/**
 * Collects measurements around provisioned DTC agents.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class ProvisionedDtcAgents extends Measurement
{
    const UNKNOWN_AGENT_VERSION = 'unknown';

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param Collector $collector
     * @param FeatureService $featureService
     * @param DateTimeService $dateTimeService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DateTimeService $dateTimeService,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'DTC provisioned agents and total recovery points';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $this->collectNumberOfProvisionedAgents($context);
        $this->collectNumberOfRecoveryPoints($context);
    }

    private function collectNumberOfProvisionedAgents(MetricsContext $context)
    {
        $provisionedAgents = [];
        foreach ($context->getDirectToCloudAgents() as $agent) {
            $agentVersion = $agent->getDriver()->getAgentVersion() ?: self::UNKNOWN_AGENT_VERSION;

            if (!isset($provisionedAgents[$agentVersion])) {
                $provisionedAgents[$agentVersion] = 0;
            }

            $provisionedAgents[$agentVersion]++;
        }

        foreach (array_keys($provisionedAgents) as $agentVersion) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_PROVISIONED, $provisionedAgents[$agentVersion], $tags);
        }
    }

    private function collectNumberOfRecoveryPoints(MetricsContext $context)
    {
        $recoveryPoints = [];

        foreach ($context->getDirectToCloudAgents() as $agent) {
            $agentVersion = $agent->getDriver()->getAgentVersion() ?: self::UNKNOWN_AGENT_VERSION;
            $localRecoveryPoints = $agent->getLocal()->getRecoveryPoints();

            if (!isset($recoveryPoints[$agentVersion])) {
                $recoveryPoints[$agentVersion] = 0;
            }

            // total number of backups taken by all agents
            $recoveryPoints[$agentVersion] += count($localRecoveryPoints->getAllRecoveryPointTimes());
        }

        foreach (array_keys($recoveryPoints) as $agentVersion) {
            $tags = [
                'agent_version' => $agentVersion
            ];

            $this->collector->measure(Metrics::STATISTIC_DTC_AGENT_BACKUPS_IN_TOTAL, $recoveryPoints[$agentVersion], $tags);
        }
    }
}
