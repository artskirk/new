<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Support auto generating SSH keys on boot rather than waiting until registration.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class SshAutoGenerateKeys extends Feature
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
