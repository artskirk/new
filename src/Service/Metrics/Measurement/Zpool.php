<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Metrics;
use Datto\System\Storage\StorageService;
use Datto\ZFS\ZpoolService;

/**
 * Collects zpool statistics.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class Zpool extends Measurement
{
    private ZpoolService $zpoolService;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        ZpoolService $zpoolService
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->zpoolService = $zpoolService;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'zpool properties';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_METRICS_ZFS);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $zpoolProperties = $this->zpoolService->getZpoolProperties(StorageService::DEFAULT_POOL_NAME);

        // zpoolProperties will be null if the command didn't work for whatever reason.
        if ($zpoolProperties !== null) {
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_SIZE, $zpoolProperties->getSize());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_ALLOCATED, $zpoolProperties->getAllocated());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_FREE, $zpoolProperties->getFree());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_CAPACITY, $zpoolProperties->getCapacity());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_DEDUPRATIO, $zpoolProperties->getDedupRatio());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_FRAGMENTATION, $zpoolProperties->getFragmentation());
            $this->collector->measure(Metrics::STATISTIC_ZFS_POOL_DISKS_COUNT, $zpoolProperties->getNumDisks());
        }
    }
}
