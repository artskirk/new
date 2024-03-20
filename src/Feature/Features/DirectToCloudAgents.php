<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports direct-to-cloud agent.
 *
 * Cases:
 *  1. cloud device and no asset provided => SUPPORTED
 *  2. cloud device and non dtc agent     => NO
 *  3. cloud device and dtc agent         => SUPPORTED
 *  4. device                             => NO
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DirectToCloudAgents extends Feature
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
     * If passed in, ensure that the target agent is direct-to-cloud.
     *
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $supported = true;

        $asset = $this->context->getAsset();
        if ($asset) {
            /** @var Agent $asset */
            $supported = $asset->isType(AssetType::AGENT) && $asset->isDirectToCloudAgent();
        }

        return $supported;
    }
}
