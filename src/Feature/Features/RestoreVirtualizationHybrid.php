<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports hybrid/cloud virtualization.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreVirtualizationHybrid extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    /** @inheritdoc */
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $servicePlan = $this->context->getServicePlanService()->get();
        $isPeerToPeer = $servicePlan->getServicePlanName() === ServicePlanService::PLAN_TYPE_PEER_TO_PEER;

        return !$deviceConfig->isSirisLite()
            && !$deviceConfig->isSnapNAS()
            && !$isPeerToPeer;
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        /* @var Agent $agent */
        $agent = $this->context->getAsset();
        $isGenericBackup = $agent instanceof Agent && !$agent->isSupportedOperatingSystem();
        $isRescueAgent = $agent instanceof Agent && $agent->isRescueAgent();
        $isShare = $agent->isType(AssetType::SHARE);
        $isMacAgent = $agent->isType(AssetType::MAC_AGENT);
        $isReplicated = $agent->getOriginDevice()->isReplicated();
        $supported = !$isReplicated
            && !$isGenericBackup
            && !$isRescueAgent
            && !$isShare
            && !$isMacAgent;

        return $supported;
    }
}
