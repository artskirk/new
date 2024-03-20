<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * If supported, a device will enable the Alert Logic agent endpoint protection service and associated rsyslog
 * configuration.
 *
 * See: https://docs.alertlogic.com/prepare/alert-logic-agent-linux.htm
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class AlertLogic extends Feature
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
