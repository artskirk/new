<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents whether this device manages its maintenance via the cloud.
 *
 * @author Sri Ramanujam <sramanujam@datto.com>
 */
class CloudManagedMaintenance extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD
        ];
    }
}
