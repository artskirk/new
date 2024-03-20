<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Device Migration feature
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceMigration extends Feature
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
    public function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $servicePlan = $this->context->getServicePlanService()->get();
        $isPeerToPeer = $servicePlan->getServicePlanName() === ServicePlanService::PLAN_TYPE_PEER_TO_PEER;

        return !$deviceConfig->has('disableDeviceMigration')
            && !$isPeerToPeer;
    }
}
