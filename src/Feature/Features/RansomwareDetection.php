<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Ransomware Detection feature
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RansomwareDetection extends Feature
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

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();
        $isWindows = $asset->isType(AssetType::WINDOWS_AGENT) || $asset->isType(AssetType::AGENTLESS_WINDOWS);
        $isNotReplicated = !$asset->getOriginDevice()->isReplicated();
        $isSupportedOperatingSystem = $asset instanceof Agent && $asset->isSupportedOperatingSystem();
        $isSupported = $isWindows && $isNotReplicated && $isSupportedOperatingSystem;

        return $isSupported;
    }
}
