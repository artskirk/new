<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\Feature;

class CloudManagedConfigs extends Feature
{
    protected function checkDeviceConstraints()
    {
        return $this->context->getDeviceConfig()->has(DeviceConfig::KEY_CLOUD_MANAGED_CONFIG);
    }
}
