<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines whether the device supports agent creation
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentCreate extends Feature
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
    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();
        $billingService = $this->context->getBillingService();

        return !$deviceConfig->isSnapNAS() &&
            !$billingService->isOutOfService();
    }
}
