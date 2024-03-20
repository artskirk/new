<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determine if the device supports deleting local critical/connecting snapshots during local retention.
 */
class AssetArchivalRetentionLocalDeleteCritical extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
    }
}
