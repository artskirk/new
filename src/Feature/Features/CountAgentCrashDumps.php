<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * If supported, a device will report the number of agent crash dumps present on disk
 *
 * @author Ryan Beatty <rbeatty@datto.com>
 */
class CountAgentCrashDumps extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::AZURE
        ];
    }
}
