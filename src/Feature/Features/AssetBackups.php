<?php

namespace Datto\Feature\Features;

use Datto\Asset\Agent\Agent;
use Datto\Feature\Feature;

/**
 * Determines asset backup support.
 */
class AssetBackups extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();

        if ($asset) {
            $isRescueAgent = $asset instanceof Agent && $asset->isRescueAgent();

            if ($asset->getOriginDevice()->isReplicated() && !$isRescueAgent) {
                return false;
            }
        }

        return true;
    }
}
