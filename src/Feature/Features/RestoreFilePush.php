<?php

namespace Datto\Feature\Features;

use Datto\Feature\Feature;

/**
 * Determines if the device supports push file restore.
 *
 * @author Ryan Mack <rmack@datto.com>
 */
class RestoreFilePush extends Feature
{
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return $deviceConfig->has('restoreFilePush');
    }
}
