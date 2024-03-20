<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Agent Templates feature
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AgentTemplates extends Feature
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
        $servicePlan = $this->context->getServicePlanService()->get();
        $isPeerToPeer = $servicePlan->getServicePlanName() === ServicePlanService::PLAN_TYPE_PEER_TO_PEER;

        $deviceConfig = $this->context->getDeviceConfig();
        return !$deviceConfig->has('agentTemplatesDisable') &&
               !$isPeerToPeer;
    }
}
