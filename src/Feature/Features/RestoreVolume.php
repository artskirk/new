<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports volume restores.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class RestoreVolume extends Feature
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
        $isGenericBackup = $agent instanceof Agent && !$agent->isSupportedOperatingSystem();
        $isIscsiShare = $agent->isType(AssetType::ISCSI_SHARE);
        $isNasShare = $agent->isType(AssetType::NAS_SHARE);
        $isExternalNasShare = $agent->isType(AssetType::EXTERNAL_NAS_SHARE);
        $isMacAgent = $agent->isType(AssetType::MAC_AGENT);
        $supported = !$isGenericBackup && !$isIscsiShare && !$isNasShare && !$isExternalNasShare && !$isMacAgent;

        return $supported;
    }
}
