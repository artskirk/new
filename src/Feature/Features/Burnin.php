<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Burnin is used when onboarding cloud devices in a datacenter. Its purpose is to stress the system and the zpool
 * to build confidence that the system survived shipping, which may have loosened cables, etc.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Burnin extends Feature
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
