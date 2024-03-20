<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Feature which controls whether or not to run the retention stage from a backup transaction
 * for direct to cloud agents.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class DirectToCloudAgentsRetentionDuringBackup extends Feature
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
