<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports iSCSI shares.
 *
 * Please note: This feature does not imply backing up the share. It merely
 * means that managing this type of share is supported.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class SharesIscsi extends Feature
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
}
