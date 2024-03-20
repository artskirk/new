<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports localhost auth
 * for API controllers.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class AuthLocalhostApiKey extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE,
            DeviceRole::CLOUD
        ];
    }
}
