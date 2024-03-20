<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Metrics\Collector;
use Datto\Log\DeviceLoggerInterface;
use Datto\Feature\FeatureService;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Metrics;
use Datto\Asset\OrphanDatasetService;

/**
 * Measurement of orphaned datasets.
 */
class OrphanedDatasets extends Measurement
{
    /** @var OrphanDatasetService */
    private $orphanDatasetService;

    /**
     * @param Collector $collector
     * @param FeatureService $featureService
     * @param OrphanDatasetService $orphanDatasetService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        OrphanDatasetService $orphanDatasetService,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->orphanDatasetService = $orphanDatasetService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'orphaned datasets';
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $orphanAgentDatasets = 0;
        $orphanShareDatasets = 0;
        foreach ($this->orphanDatasetService->findOrphanDatasets() as $orphanDataset) {
            if ($this->orphanDatasetService->isAgentDataset($orphanDataset)) {
                $orphanAgentDatasets++;
            } elseif ($this->orphanDatasetService->isShareDataset($orphanDataset)) {
                $orphanShareDatasets++;
            }
        }

        $this->collector->measure(Metrics::STATISTIC_AGENT_DATASET_ORPHAN, $orphanAgentDatasets);
        $this->collector->measure(Metrics::STATISTIC_SHARE_DATASET_ORPHAN, $orphanShareDatasets);
    }
}
