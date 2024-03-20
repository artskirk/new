<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * If supported, a device will attempt to automatically activate itself with device-web on boot.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class AutoActivate extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
    }
}
