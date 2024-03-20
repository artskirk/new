<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Represents the Hypervisor Connection feature
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class HypervisorConnections extends Feature
{
    /**
     * {@inheritdoc}
     */
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
        $deviceConfig = $this->context->getDeviceConfig();

        $supportsHypervisorConnections = $deviceConfig->isVirtual() ||
            (!$deviceConfig->isSnapNAS() &&
            !$deviceConfig->isAlto() &&
            !$deviceConfig->isAltoXL());

        return $supportsHypervisorConnections;
    }
}
