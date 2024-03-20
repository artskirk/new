<?php


namespace Datto\Service\Metrics\Measurement;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Service\Retention\RetentionFactory;
use Datto\Service\Retention\RetentionService;
use Datto\Service\Retention\RetentionType;
use Datto\Log\DeviceLoggerInterface;

class RemainingRetentionCount extends Measurement
{

    /** @var AssetService */
    private $assetService;

    /** @var RetentionFactory */
    private $retentionFactory;

    /** @var RetentionService */
    private $retentionService;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        AssetService $assetService,
        RetentionFactory $retentionFactory,
        RetentionService $retentionService
    ) {
        parent::__construct($collector, $featureService, $logger);

        $this->assetService = $assetService;
        $this->retentionFactory = $retentionFactory;
        $this->retentionService = $retentionService;
    }
    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'remaining retention count for all agents on device';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_METRICS_REMAINING_RETENTION_COUNT);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        $this->collector->measure(
            Metrics::STATISTIC_RETENTION_LOCAL_REMAINING_COUNT,
            $this->getRemainingRetentionCount($context->getAgents(), RetentionType::LOCAL())
        );

        $this->collector->measure(
            Metrics::STATISTIC_RETENTION_OFFSITE_REMAINING_COUNT,
            $this->getRemainingRetentionCount($context->getAgents(), RetentionType::OFFSITE())
        );
    }

    /**
     * @param Agent[] $agents
     * @param RetentionType $type
     * @return int
     */
    private function getRemainingRetentionCount(array $agents, RetentionType $type): int
    {
        $remaining = 0;
        $retentions = [];

        foreach ($agents as $agent) {
            $retentions[] = $this->retentionFactory->create($agent, $type);
        }

        foreach ($retentions as $retention) {
            try {
                $dryRunResults = $this->retentionService->dryRunRetention($retention);
                $remaining += count($dryRunResults);
            } catch (\Exception $e) {
                $this->logger->warning('RRC0001 Unable to get retention count for asset', ['exception' => $e]);
            }
        }

        return $remaining;
    }
}
