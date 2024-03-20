<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;
use Datto\Virtualization\HypervisorType;

/**
 * The RoundTrip feature
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class Roundtrip extends Feature
{
    const KEY_FILE = 'hypervisor';

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
        // hypervisor key is cached at startup by CacheHypervisorTask
        $hypervisor = $this->context->getDeviceConfig()->get(static::KEY_FILE);
        return $hypervisor !== HypervisorType::HYPER_V()->key();
    }
}
