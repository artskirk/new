<?php

namespace Datto\Service\Retention;

use Datto\Asset\Asset;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Feature\FeatureService;
use Datto\Service\Retention\Strategy\RetentionStrategyInterface;
use Psr\Log\LoggerInterface;

abstract class AbstractRetentionStrategy implements RetentionStrategyInterface
{
    protected Asset $asset;
    protected FeatureService $featureService;
    protected RecoveryPointInfoService $recoveryPointService;
    protected LoggerInterface $logger;
    protected int $totalPoints = 0;

    public function __construct(
        Asset $asset,
        FeatureService $featureService,
        RecoveryPointInfoService $recoveryPointService,
        LoggerInterface $logger
    ) {
        $this->asset = $asset;
        $this->featureService = $featureService;
        $this->recoveryPointService = $recoveryPointService;
        $this->logger = $logger;
    }
}
