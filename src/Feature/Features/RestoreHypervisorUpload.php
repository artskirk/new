<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports restoring by hypervisor upload (ESX/Hyper-V).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreHypervisorUpload extends Feature
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
        $isGenericBackup = $agent instanceof Agent && !$agent->isSupportedOperatingSystem();
        $isRescueAgent = $agent instanceof Agent && $agent->isRescueAgent();
        $isShare = $agent->isType(AssetType::SHARE);
        $isMacAgent = $agent->isType(AssetType::MAC_AGENT);
        $supported = !$isGenericBackup
            && !$isRescueAgent
            && !$isShare
            && !$isMacAgent;

        return $supported;
    }
}
