<?php

namespace Datto\Feature\Features;

use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Share;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device should automatically mount share zvols when they
 * are loaded from disk.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ShareAutoMounting extends Feature
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
        $asset = $this->context->getAsset();

        return $asset instanceof Share &&
            !($asset instanceof IscsiShare) &&
            !$asset->getOriginDevice()->isReplicated();
    }
}
