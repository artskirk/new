<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Security wanted us to install intrusion detection software for SOC2 compliance, so, this feature determines if that
 * is enabled. It will be installed in the image for all devices but, only enabled/started on cloud devices.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IntrusionDetection extends Feature
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
