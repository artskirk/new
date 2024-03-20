<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports registration.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Registration extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }
}
