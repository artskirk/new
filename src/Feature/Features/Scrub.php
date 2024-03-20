<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Scrub endpoints are used to both trigger scrubs and get the status of those scrubs.
 * Scrubs should only be possible on cloud devices where there are limited replicas.
 *
 * @author Bryan Ehrlich <behrlich@datto.com>
 */
class Scrub extends Feature
{
    /**
     * {@inheritdoc}
     */
    protected function getSupportedDeviceRoles(): array
    {
        return [
            DeviceRole::CLOUD
        ];
    }
}
