<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

class CloudFeatures extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE,
            DeviceRole::CLOUD
        ];
    }
}
