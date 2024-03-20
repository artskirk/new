<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\Feature;

/**
 * Determines if the device supports adding firewall rules to prevent network hopping via IP forwarding.
 */
class PreventNetworkHopping extends Feature
{
    protected function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return !$deviceConfig->has(DeviceConfig::KEY_PREVENT_NETWORK_HOPPING);
    }
}
