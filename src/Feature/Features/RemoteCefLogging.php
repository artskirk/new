<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports remote logging via CEF.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RemoteCefLogging extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }
}
