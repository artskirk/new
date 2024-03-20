<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports public cloud virtualization.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class RestoreVirtualizationPublicCloud extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
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

        return !$isReplicated
            && !$isGenericBackup
            && !$isRescueAgent
            && !$isShare
            && !$isMacAgent;
    }
}
