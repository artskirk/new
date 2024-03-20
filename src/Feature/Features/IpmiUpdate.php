<?php

namespace Datto\Feature\Features;

use Datto\Feature\DeviceRole;
use Datto\Ipmi\FlashableIpmi;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiUpdate extends Ipmi
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
        $ipmiSupported = parent::checkDeviceConstraints();
        $flashingSupported = FlashableIpmi::isSupported();

        return $ipmiSupported && $flashingSupported;
    }
}
