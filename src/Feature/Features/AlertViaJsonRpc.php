<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Alerting via Device Web's JSON RPC endpoint instead of sendnotice4.
 *
 * @author Matthew Crowson <mcrowson@datto.com>
 */
class AlertViaJsonRpc extends Feature
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
