<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports configuring and starting Suricata
 *
 * @author Huan-Yu Yih <hyih@datto.com>
 */
class Suricata extends Feature
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
