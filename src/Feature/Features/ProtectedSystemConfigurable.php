<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the agent supports protected machine configuration requests
 *
 * @author Jess Gentner <jgentner@datto.com>
 */
class ProtectedSystemConfigurable extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD,
            DeviceRole::AZURE
        ];
    }

    /**
     * If passed in, ensure that the target agent is direct-to-cloud and not replicated.
     *
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $supported = true;

        $asset = $this->context->getAsset();
        if ($asset) {

            /** @var Agent $asset */
            $directToCloudAgent = $asset->isType(AssetType::AGENT) && $asset->isDirectToCloudAgent();

            $isRescueAgent = $asset->isType(AssetType::AGENT) && $asset->isRescueAgent();
            $isReplicated = $asset->getOriginDevice()->isReplicated() && !$isRescueAgent;

            $supported = $directToCloudAgent && !$isReplicated;
        }

        return $supported;
    }
}
