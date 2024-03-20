<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Customize Background feature
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CustomBackground extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isAlto();
    }
}
