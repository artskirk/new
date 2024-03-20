<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determine if the device supports taking partial backups (configuration) to the configBackup dataset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ConfigBackupsPartial extends Feature
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
