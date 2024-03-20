<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Util\OsFamily;

/**
 * Network interface builder for ESX Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxNetworkInterfacesBuilder extends DefaultNetworkInterfacesBuilder
{
    /**
     * @inheritdoc
     */
    protected function getInterfaceModel(): string
    {
        $interfaceModel = parent::getInterfaceModel();

        // only override if the user has not explicitly configured
        if (!$this->getContext()->getVmSettings()->isUserDefined()) {
            $guestOs = $this->getContext()->getGuestOs();

            // older Windows versions don't support e1000e, fallback to e1000
            // linux only supports e1000
            if (($this->isLegacyWindowsOs() && $interfaceModel === 'e1000e')
                || $guestOs->getOsFamily() === OsFamily::LINUX()) {
                $interfaceModel = 'e1000';
            }
        }
        return $interfaceModel;
    }
}
