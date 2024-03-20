<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\DirectToCloud\DirectToCloudCommander;
use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Log\DeviceLoggerInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ActiveDtcCommanderProcesses extends Measurement
{
    /** @var DirectToCloudCommander */
    private $commander;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        DirectToCloudCommander $commander
    ) {
        parent::__construct($collector, $featureService, $logger);
        $this->commander = $commander;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'number of dtccommander processes';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS);
    }

    /**
     * @inheritDoc
     */
    public function collect(MetricsContext $context)
    {
        $this->collector->measure(Metrics::STATISTIC_DTC_COMMANDER_PROCESSES, $this->commander->count());
    }
}
