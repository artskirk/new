<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Check to see if we enable encrypted storage (ie. LUKS encrypted disks backing ZFS).
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class StorageEncryption extends Feature
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
