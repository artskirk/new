<?php
namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports virtualizations in general (no destinction between
 * different virtualization types!).
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreVirtualization extends Feature
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

    /** @inheritdoc */
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->isSirisLite()
            && !$deviceConfig->isSnapNAS();
    }
}
