<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;

/**
 * Indicates that a device should rotate the IPMI Admin user password
 */
class IpmiRotateAdminPassword extends Ipmi
{
    /**
     * This is more restrictive than the parent Ipmi class, since cloud devices are not supported by this feature
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }
}
