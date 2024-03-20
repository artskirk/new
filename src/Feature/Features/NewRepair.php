<?php

namespace Datto\Feature\Features;

use Datto\Feature\Feature;

/**
 * Determines if the device supports the new repairing process.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class NewRepair extends Feature
{
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return $deviceConfig->has('newRepair');
    }
}
