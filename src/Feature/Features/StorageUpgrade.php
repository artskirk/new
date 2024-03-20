<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports storage upgrades.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class StorageUpgrade extends Feature
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
