<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Billing\ServicePlanService;

/**
 * Determines if the device supports offsiting its configBackup share.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class ConfigBackupOffsite extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE,
            DeviceRole::PHYSICAL,
            DeviceRole::VIRTUAL
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function checkDeviceConstraints()
    {
        $billingService = $this->context->getBillingService();
        $servicePlan = $this->context->getServicePlanService()->get();
        $isPeerToPeer = $servicePlan->getServicePlanName() === ServicePlanService::PLAN_TYPE_PEER_TO_PEER;

        return !$billingService->isLocalOnly()
            && !$billingService->isOutOfService()
            && !$isPeerToPeer;
    }
}
