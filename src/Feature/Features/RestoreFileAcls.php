<?php

namespace Datto\Feature\Features;

use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports file restores.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class RestoreFileAcls extends Feature
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
    protected function checkAssetConstraints()
    {
        /* @var ExternalNasShare $asset */
        $asset = $this->context->getAsset();
        $supported = $asset instanceof ExternalNasShare && $asset->isBackupAclsEnabled();

        return $supported;
    }
}
