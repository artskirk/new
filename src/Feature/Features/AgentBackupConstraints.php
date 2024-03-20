<?php

namespace Datto\Feature\Features;

use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Asset\Agent\Agent;

/**
 * Determines if the device supports constraints on agent backups.
 */
class AgentBackupConstraints extends Feature
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
        if ($asset instanceof Agent) {
            /** @var Agent $asset */
            $directToCloudAgent = $asset->isType(AssetType::AGENT) && $asset->isDirectToCloudAgent();

            return $directToCloudAgent;
        }

        return false;
    }
}
