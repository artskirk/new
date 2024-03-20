<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\Feature;

/**
 * Determine if metrics are enabled for the device
 *
 * @author Devin Matte <dmatte@datto.com>
 */
class Metrics extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return $deviceConfig->has(DeviceConfig::KEY_ENABLE_METRICS);
    }
}
