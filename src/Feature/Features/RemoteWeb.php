<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports remote web and if configuring the
 * force login feature is possible.
 *
 * Please note: This feature does NOT apply to all of RLY!
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RemoteWeb extends Feature
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
