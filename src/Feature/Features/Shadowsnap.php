<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Feature which controls whether the UI is allowed to pair new ShadowSnap agents
 *
 * @author Jakob Wiesmore <jwiesmore@datto.com>
 */
class Shadowsnap extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->has(DeviceConfig::KEY_DISABLE_SHADOWSNAP_PAIRING);
    }
}
