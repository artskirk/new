<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Indicates that HyperShuttle (agentless snapshot copy) is supported
 *
 * @author Oliver Castaneda <ocastaneda@datto.com>
 */
class HyperShuttle extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    protected function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return $deviceConfig->has(DeviceConfig::KEY_USE_HYPER_SHUTTLE);
    }
}
