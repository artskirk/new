<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Common\Resource\Filesystem;
use Datto\Service\Metrics\Measurement;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Metrics\Metrics;

/**
 * Count and report number of agent crash dumps
 *
 * @author Ryan Beatty <rbeatty@datto.com>
 */
class AgentCrashDumps extends Measurement
{
    private Filesystem $filesystem;

    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger,
        Filesystem $filesystem
    ) {
        parent::__construct($collector, $featureService, $logger);
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'number of agent crash dumps present on this device';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_COUNT_AGENT_CRASH_DUMPS);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $agent_dump_files = $this->filesystem->glob('/home/agents/logs/*/*.dmp');
        $this->collector->measure(Metrics::STATISTIC_AGENT_CRASH_DUMPS, count($agent_dump_files));
    }
}
