<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports the local bandwidth scheduling.  This is turned on by default.  If it's turned off,
 * checkin will generate the shaper.sh rules, instead of leaving it to the datto-bandwidth-schedule systemd service.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class LocalBandwidthScheduling extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->has('disableLocalBandwidthScheduling');
    }
}
