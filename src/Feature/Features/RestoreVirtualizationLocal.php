<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports local virtualization.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreVirtualizationLocal extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::CLOUD
        ];
    }

    /** @inheritdoc */
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isAlto()
            && !$deviceConfig->isAltoXL()
            && !$deviceConfig->isSirisLite()
            && !$deviceConfig->isSnapNAS();
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        /* @var Agent $agent */
        $agent = $this->context->getAsset();
        $isRescueAgent = $agent instanceof Agent && $agent->isRescueAgent();
        $isShare = $agent->isType(AssetType::SHARE);
        $isMacAgent = $agent->isType(AssetType::MAC_AGENT);
        $isUvm = $agent instanceof Agent && !$agent->isSupportedOperatingSystem();
        $deviceConfig = $this->context->getDeviceConfig();
        $isUvmLocalVirtFeatureFlagEnabled = $deviceConfig->get(DeviceConfig::KEY_ENABLE_UVM_LOCAL_VIRT);
        $supported = !$isRescueAgent
            && !$isShare
            && !$isMacAgent
            && (!$isUvm || $isUvmLocalVirtFeatureFlagEnabled);

        return $supported;
    }
}
