<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports the SFTP option for file restores.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class RestoreFileSftp extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD,
            DeviceRole::AZURE
        ];
    }
}
