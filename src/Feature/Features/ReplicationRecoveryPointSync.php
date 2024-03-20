<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device fetches recoverypoint info from the cloud for all
 * replicated assets.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ReplicationRecoveryPointSync extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        // Before this can support all replicant devices the cloud portion needs to be refactored.
        // Reaching out to every source device every hour to get all recoverypoint info is not scalable.

        return [
            DeviceRole::CLOUD
        ];
    }
}
