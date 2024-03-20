<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device fetches volume info from the cloud for all replicated assets.
 */
class ReplicationVolumesSync extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD
        ];
    }

    /**
     * @inheritDoc
     */
    protected function checkAssetConstraints()
    {
        /** @var Agent $asset */
        $asset = $this->context->getAsset();

        return $asset->isType(AssetType::AGENT)
            && $asset->getPlatform() === AgentPlatform::DIRECT_TO_CLOUD();
    }
}
