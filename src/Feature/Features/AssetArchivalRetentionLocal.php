<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determine if the device supports running local retention for archived assets.
 */
class AssetArchivalRetentionLocal extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
    }
}
