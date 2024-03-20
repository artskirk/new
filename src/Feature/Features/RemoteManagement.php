<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if this machine supports remote commands (via. salt).
 *
 * TODO: come up with a good feature name :-)
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoteManagement extends Feature
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
