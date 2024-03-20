<?php

namespace Datto\Feature\Features;

use Datto\Feature\Feature;

/**
 * Determines if the device supports the new pairing process.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class NewPair extends Feature
{
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return $deviceConfig->has('newPair');
    }
}
