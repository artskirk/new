<?php

namespace Datto\Service\Retention;

use Datto\Asset\Asset;
use Datto\Asset\RecoveryPoint\LocalSnapshotService;
use Datto\Asset\RecoveryPoint\OffsiteSnapshotService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\LocalConfig;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\Retention\RetentionType;
use Datto\Service\Retention\Strategy\LocalRetention;
use Datto\Service\Retention\Strategy\OffsiteRetention;
use Datto\Service\Retention\Strategy\RetentionStrategyInterface;
use Psr\Log\LoggerAwareInterface;

/**
 * Factory for RetentionStrategyInterface instances.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class RetentionFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var FeatureService */
    private $featureService;

    /** @var LocalConfig */
    private $localConfig;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    /** @var RecoveryPointInfoService */
    private $recoveryPointService;

    /** @var LocalSnapshotService */
    private $localSnapshotService;

    /** @var OffsiteSnapshotService */
    private $offsiteSnapshotService;

    public function __construct(
        FeatureService $featureService,
        LocalConfig $localConfig,
        SpeedSyncMaintenanceService $speedSyncMaintenanceService,
        RecoveryPointInfoService $recoveryPointService,
        LocalSnapshotService $localSnapshotService,
        OffsiteSnapshotService $offsiteSnapshotService
    ) {
        $this->featureService = $featureService;
        $this->localConfig = $localConfig;
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
        $this->recoveryPointService = $recoveryPointService;
        $this->localSnapshotService = $localSnapshotService;
        $this->offsiteSnapshotService = $offsiteSnapshotService;
    }

    public function create(Asset $asset, RetentionType $type): RetentionStrategyInterface
    {
        $this->logger->setAssetContext($asset->getKeyName());

        $ret = null;
        switch ($type) {
            case RetentionType::LOCAL():
                $ret = new LocalRetention(
                    $asset,
                    $this->featureService,
                    $this->recoveryPointService,
                    $this->logger,
                    $this->localSnapshotService
                );

                break;
            case RetentionType::OFFSITE():
                $ret = new OffsiteRetention(
                    $asset,
                    $this->featureService,
                    $this->recoveryPointService,
                    $this->logger,
                    $this->localConfig,
                    $this->speedSyncMaintenanceService,
                    $this->offsiteSnapshotService
                );

                break;
        }

        return $ret;
    }
}
