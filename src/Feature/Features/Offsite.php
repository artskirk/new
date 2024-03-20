<?php

namespace Datto\Feature\Features;

use Datto\Cloud\SpeedSync;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Cloud Storage feature
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Offsite extends Feature
{
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL,
            DeviceRole::AZURE
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function checkAssetConstraints()
    {
        $asset = $this->context->getAsset();

        if ($asset === null) {
            return true;
        }

        $isReplicated = $asset->getOriginDevice()->isReplicated();
        $canOffsite = $asset->getOffsiteTarget() !== SpeedSync::TARGET_NO_OFFSITE;

        return !$isReplicated && $canOffsite;
    }

    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $billingService = $this->context->getBillingService();

        return !$billingService->isLocalOnly()
            && !$billingService->isOutOfService();
    }
}
