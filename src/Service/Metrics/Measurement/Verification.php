<?php

namespace Datto\Service\Metrics\Measurement;

use Datto\Service\Metrics\Measurement;
use Datto\Metrics\Collector;
use Datto\Feature\FeatureService;
use Datto\Log\DeviceLoggerInterface;
use Datto\Verification\VerificationQueue;
use Datto\Service\Metrics\MetricsContext;
use Datto\Metrics\Metrics;

/**
 * Collects verification related statistics
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class Verification extends Measurement
{
    /** @var VerificationQueue */
    private $verificationQueue;

    /**
     * @param Collector $collector
     * @param FeatureService $featureService
     * @param VerificationQueue $verificationQueue
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        Collector $collector,
        FeatureService $featureService,
        VerificationQueue $verificationQueue,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct(
            $collector,
            $featureService,
            $logger
        );

        $this->verificationQueue = $verificationQueue;
    }

    /**
     * @inheritDoc
     */
    public function description(): string
    {
        return 'verification queue length';
    }

    /**
     * @inheritDoc
     */
    public function enabled(): bool
    {
        return $this->featureService->isSupported(FeatureService::FEATURE_VERIFICATIONS);
    }

    /**
     * @inheritdoc
     */
    public function collect(MetricsContext $context)
    {
        $this->collector->measure(Metrics::STATISTIC_VERIFICATION_QUEUE_LENGTH, $this->verificationQueue->getCount());
    }
}
