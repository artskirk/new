<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports receiving assets and snapshots
 * from another SIRIS
 *
 * @author John Roland <jroland@datto.com>
 */
class ReplicationTarget extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::CLOUD
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        $unsupportedModel = $deviceConfig->isAlto() || $deviceConfig->isAlto3() || $deviceConfig->isAltoXL()
            ||  $deviceConfig->isSnapNAS() || $deviceConfig->isSirisLite();

        return $deviceConfig->isCloudDevice() || !$unsupportedModel;
    }
}
