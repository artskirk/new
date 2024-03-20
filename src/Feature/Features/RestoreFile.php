<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetType;
use Datto\Feature\Feature;

/**
 * Determines if the device supports file restores.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class RestoreFile extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        /* @var Agent $agent */
        $agent = $this->context->getAsset();
        $isGenericBackup = $agent instanceof Agent && !$agent->isSupportedOperatingSystem();
        $isIscsiShare = $agent->isType(AssetType::ISCSI_SHARE);
        $supported = !$isGenericBackup && !$isIscsiShare;

        return $supported;
    }
}
