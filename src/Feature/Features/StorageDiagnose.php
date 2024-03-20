<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports storage diganosing features (such as blinking the drive light).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class StorageDiagnose extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL
        ];
    }
}
