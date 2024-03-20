<?php

namespace Datto\Feature\Features;

use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;

/**
 * Determine if an asset is allowed to perform service verifications.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ServiceVerifications extends Verifications
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
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        try {
            if (!parent::checkAssetConstraints()) {
                return false;
            }

            $asset = $this->context->getAsset();
            $isNonAgentlessWindows = $asset->isType(AssetType::WINDOWS_AGENT);
            $isAgentlessWindows = $asset->isType(AssetType::AGENTLESS_WINDOWS);

            return $isNonAgentlessWindows || $isAgentlessWindows;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
