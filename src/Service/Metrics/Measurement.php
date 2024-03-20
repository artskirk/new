<?php

namespace Datto\Service\Metrics;

use Datto\Metrics\Collector;
use Datto\Log\DeviceLoggerInterface;
use Datto\Feature\FeatureService;

/**
 * Base class for metrics measurements
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
abstract class Measurement
{
    /** @var Collector */
    protected $collector;

    /** @var FeatureService */
    protected $featureService;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param Collector $collector
     * @param FeatureService $featureService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        DeviceLoggerInterface $logger
    ) {
        $this->collector = $collector;
        $this->featureService = $featureService;
        $this->logger = $logger;
    }

    /**
     * Get a description of what we're collecting for logging purposes.
     *
     * @return string
     */
    abstract public function description(): string;

    /**
     * Check if this measurement should be enabled. All are enabled by default unless overridden.
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return true;
    }

    /**
     * Collect the measurement.
     *
     * @param MetricsContext $context
     * @return void
     */
    abstract public function collect(MetricsContext $context);
}
