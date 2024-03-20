<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports direct-to-cloud agent multi volume backup.
 */
class DirectToCloudAgentsMultiVolume extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE,
            DeviceRole::CLOUD
        ];
    }

    protected function checkAssetConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $asset = $this->context->getAsset();
        /* existing azure agents won't have the shouldbackupallvolumes flag set in backup constraints key
           file, so we just always let them through. */
        $shouldBackupAllVolumes = $asset->getBackupConstraints() ?
            $asset->getBackupConstraints()->shouldBackupAllVolumes() : false;
        return $deviceConfig->isAzureDevice() || $shouldBackupAllVolumes;
    }
}
