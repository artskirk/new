<?php

namespace Datto\Feature\Features;

use Datto\Feature\Feature;
use Datto\Feature\DeviceRole;

/**
 * Determines whether zpool storage quota should be set for the pool
 *
 * @author Bryan Ehrlich <behrlich@datto.com>
 */
class SetPoolQuota extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::CLOUD
        ];
    }
}
