<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines of this machine supports remote logging (eg. filebeat).
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RemoteLogging extends Feature
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
