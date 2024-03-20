<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports iSCSI rollbacks.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreIscsiRollback extends Feature
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
        /* @var Agent $agent */
        $agent = $this->context->getAsset();
        $isIscsiShare = $agent->isType(AssetType::ISCSI_SHARE);
        $isReplicated = $agent->getOriginDevice()->isReplicated();
        $supported = $isIscsiShare && !$isReplicated;

        return $supported;
    }
}
