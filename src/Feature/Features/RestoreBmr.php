<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the BMR feature
 *
 * @author Justin Giacobbi <justin@datto.com>
 */
class RestoreBmr extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::CLOUD,
            DeviceRole::AZURE
        ];
    }

    /**
     * @inheritdoc
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isSnapNAS();
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        /* @var Agent $asset */
        $asset = $this->context->getAsset();
        $isGenericBackup = $asset instanceof Agent && !$asset->isSupportedOperatingSystem();
        $isShare = $asset->isType(AssetType::SHARE);
        $supported = !$isGenericBackup
            && !$isShare;

        return $supported;
    }
}
