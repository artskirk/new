<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Whether the device supports differential rollbacks (DTC Rapid Rollback).
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class RestoreDifferentialRollback extends Feature
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
