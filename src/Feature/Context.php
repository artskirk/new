<?php

namespace Datto\Feature;

use Datto\Asset\Agent\ArchiveService;
use Datto\Asset\Asset;
use Datto\Billing\Service as BillingService;
use Datto\Billing\ServicePlanService;
use Datto\Config\DeviceConfig;

/**
 * Class Context
 * The current state of the device and asset that the feature constraints should be checked against.
 * Can and should be extended to support both new and existing assets, as well as anything else a feature may
 * depend on.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Context
{
    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var Asset */
    private $asset;

    /** @var string */
    private $assetClass;

    /** @var ServicePlanService */
    private $servicePlanService;

    /** @var BillingService */
    private $billingService;

    /** @var ArchiveService */
    private $archiveService;

    public function __construct(
        DeviceConfig $deviceConfig,
        ServicePlanService $servicePlanService,
        BillingService $billingService,
        ArchiveService $archiveService,
        Asset $asset = null,
        string $assetClass = null
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->servicePlanService = $servicePlanService;
        $this->billingService = $billingService;
        $this->archiveService = $archiveService;
        $this->asset = $asset;
        $this->assetClass = $assetClass;
    }

    /**
     * @param Asset|null $asset
     */
    public function setAsset(Asset $asset = null)
    {
        $this->asset = $asset;
    }

    /**
     * @param string|null $assetClass
     */
    public function setAssetClass(string $assetClass = null)
    {
        $this->assetClass = $assetClass;
    }

    /**
     * @return BillingService
     */
    public function getBillingService(): BillingService
    {
        return $this->billingService;
    }

    /**
     * @return ArchiveService
     */
    public function getArchiveService(): ArchiveService
    {
        return $this->archiveService;
    }

    /**
     * @return DeviceConfig
     */
    public function getDeviceConfig(): DeviceConfig
    {
        return $this->deviceConfig;
    }

    /**
     * @return ServicePlanService
     */
    public function getServicePlanService(): ServicePlanService
    {
        return $this->servicePlanService;
    }

    /**
     * @return Asset|null
     */
    public function getAsset()
    {
        return $this->asset;
    }

    /**
     * @return string|null
     */
    public function getAssetClass()
    {
        return $this->assetClass;
    }
}
