<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device/asset combo supports being "promoted" from a replicated asset to a non-replicated asset
 * and "demoted" from a non-replicated asset to a replicated asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ReplicationPromote extends Feature
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
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();

        if (!$asset->isType(AssetType::AGENT)) {
            return false;
        }

        /** @var Agent $asset */
        return $asset->getPlatform() === AgentPlatform::DIRECT_TO_CLOUD();
    }
}
