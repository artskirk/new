<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\HealthService;
use Datto\Metrics\Metrics;

/**
 * Collect node health scores.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class HealthScores extends Measurement
{
    /** @var HealthService */
    private $healthService;

    /**
     * @param Collector $collector
     * @param FeatureService $featureService
     * @param HealthService $healthService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        HealthService $healthService,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->healthService = $healthService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'health check scores';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_DEVICE_INFO);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        if (!$this->featureService->isSupported(FeatureService::FEATURE_DEVICE_INFO)) {
            return;
        }

        $health = $this->healthService->calculateHealthScores();

        $this->collector->measure(Metrics::STATISTIC_HEALTH_ZPOOL, $health->getZpoolHealthScore());
        $this->collector->measure(Metrics::STATISTIC_HEALTH_MEMORY, $health->getMemoryHealthScore());
        $this->collector->measure(Metrics::STATISTIC_HEALTH_CPU, $health->getCpuHealthScore());
        $this->collector->measure(Metrics::STATISTIC_HEALTH_IOPS, $health->getIopsHealthScore());
    }
}
