<?php

namespace Datto\Feature\Features;

use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;

/**
 * Determine if an asset is allowed to perform application verifications.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ApplicationVerifications extends Verifications
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::AZURE
        ];
    }

    /**
     * Check whether or not the feature is supported for the asset
     *
     * @return bool
     */
    protected function checkAssetConstraints()
    {
        try {
            if (!parent::checkAssetConstraints()) {
                return false;
            }

            $asset = $this->context->getAsset();
            $isWindows = $asset->isType(AssetType::WINDOWS_AGENT) || $asset->isType(AssetType::AGENTLESS_WINDOWS);

            return $isWindows;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
