<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports firewall which allows only specific ports with default rule being REJECT.
 */
class RestrictiveFirewall extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return !$deviceConfig->has(DeviceConfig::KEY_DISABLE_RESTRICTIVE_FIREWALL);
    }
}
