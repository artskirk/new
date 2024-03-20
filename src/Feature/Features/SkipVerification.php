<?php


namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports skipping screenshot verification
 * (used in case of backup with pending OS update)
 */
class SkipVerification extends Feature
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

    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return $deviceConfig->isCloudDevice();
    }
}
