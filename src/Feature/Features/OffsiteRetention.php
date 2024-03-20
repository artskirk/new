<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines if the device supports running offsite retention.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class OffsiteRetention extends Feature
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
    protected function checkDeviceConstraints()
    {
        $billingService = $this->context->getBillingService();

        return !$billingService->isLocalOnly()
            && !$billingService->isOutOfService();
    }
}
