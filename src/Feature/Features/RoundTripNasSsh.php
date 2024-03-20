<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\Features\RoundtripNas;

/**
 * The SSH feature for RoundTrip NAS transfers
 *
 * @author Scott Ventura <sventura@datto.com>
 */
class RoundTripNasSsh extends RoundtripNas
{
    protected function checkDeviceConstraints(): bool
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return $deviceConfig->get(DeviceConfig::KEY_ENABLE_RT_NG_NAS_SSH);
    }
}
