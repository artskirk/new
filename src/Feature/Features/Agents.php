<?php

namespace Datto\Feature\Features;

use Datto\Feature\Feature;

/**
 * Determines if the device supports agents.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class Agents extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        return !$deviceConfig->isSnapNAS();
    }
}
