<?php

namespace Datto\Feature\Features;

use Datto\Config\DeviceConfig;
use Datto\Feature\DeviceRole;
use Datto\Feature\Feature;

/**
 * Determines whether to show a warning on the agents page if any agent certificates are going to be expiring
 * within 30 days.  The constraint of this feature should be overridden to show the warning if we ever get
 * within 7 days of cert expiration.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class CertExpirationWarning extends Feature
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

    protected function checkDeviceConstraints()
    {
        $deviceConfig = $this->context->getDeviceConfig();

        return !$deviceConfig->has(DeviceConfig::KEY_DISABLE_CERT_EXPIRATION_WARNING);
    }
}
