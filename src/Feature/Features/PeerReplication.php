<?php

namespace Datto\Feature\Features;

use Datto\Billing\ServicePlanService;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Peer to Peer Replication feature
 *
 * @author Andrew Cope <acope@datto.com>
 */
class PeerReplication extends Feature
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
    public function checkDeviceConstraints(): bool
    {
        $servicePlan = $this->context->getServicePlanService()->get();
        $isPeerToPeer = $servicePlan->getServicePlanName() === ServicePlanService::PLAN_TYPE_PEER_TO_PEER;

        return $isPeerToPeer;
    }
}
