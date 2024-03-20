<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\ZFS\ZfsDatasetService;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class MountedZfsDatasets extends Measurement
{
    const IGNORABLE_DATASETS = [
        'homePool/os'
    ];

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /**
     * @inheritDoc
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        ZfsDatasetService $zfsDatasetService
    ) {
        parent::__construct($collector, $featureService, $logger);
        $this->zfsDatasetService = $zfsDatasetService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'zfs datasets mounted';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_METRICS_ZFS);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        try {
            $datasets = $this->zfsDatasetService->getAllDatasets();
        } catch (\Exception $e) {
            $datasets = [];
        }

        $mounted = [];

        foreach ($datasets as $dataset) {
            if (!$dataset->isMountable()) {
                continue;
            }

            $this->collector->measure(Metrics::STATISTICS_ZFS_DATASETS_NOT_MOUNTED, $dataset->isMounted(false) ? 0 : 1, [
                'dataset_name' => $dataset->getName()
            ]);
        }
    }
}
