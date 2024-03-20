<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Log\DeviceLoggerInterface;
use Datto\Utility\Storage\Zpool;
use Datto\ZFS\ZpoolService;
use Throwable;

/** Ensure the desired set of zpool features is enabled */
class UpgradeZpoolFeatures implements ConfigRepairTaskInterface
{
    /** @var Zpool */
    private $zpool;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var string[] */
    private $expectedFeatures;

    public function __construct(
        Zpool $zpool,
        DeviceLoggerInterface $logger,
        array $expectedFeatures = Zpool::POOL_FEATURES
    ) {
        $this->zpool = $zpool;
        $this->logger = $logger;
        $this->expectedFeatures = $expectedFeatures;
    }

    /** @inheritDoc */
    public function run(): bool
    {
        $preUpgradeFeatures = $this->zpool->getFeatures(ZpoolService::HOMEPOOL);

        try {
            $this->zpool->upgradeFeatures(ZpoolService::HOMEPOOL);
        } catch (Throwable $t) {
            $this->logger->error('UZF0001 Exception occurred while upgrading zpool features', ['exception' => $t]);
        }

        $postUpgradeFeatures = $this->zpool->getFeatures(ZpoolService::HOMEPOOL);

        $enabledFeatures = array_diff($postUpgradeFeatures, $preUpgradeFeatures);
        if ($enabledFeatures) {
            $this->logger->info('UZF0002 Enabled features', ['enabledFeatures' => $enabledFeatures]);
        }

        $missingFeatures = array_diff($this->expectedFeatures, $postUpgradeFeatures);
        if ($missingFeatures) {
            $this->logger->warning('UZF0003 Device is missing features', ['missingFeatures' => $missingFeatures]);
        }

        $extraFeatures = array_diff($postUpgradeFeatures, $this->expectedFeatures);
        if ($extraFeatures) {
            $this->logger->warning('UZF0004 Device has extra features enabled', ['extraFeatures' => $extraFeatures]);
        }

        return !empty($enabledFeatures);
    }
}
