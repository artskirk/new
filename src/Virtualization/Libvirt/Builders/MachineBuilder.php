<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Util\OsFamily;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;

/**
 * General settings builder
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class MachineBuilder extends BaseVmDefinitionBuilder
{
    public function build(VmDefinition $vmDefinition)
    {
        $guestOs = $this->getContext()->getGuestOs();
        $vmSettings = $this->getContext()->getVmSettings();

        $vmDefinition->setType($this->getContext()->getVmHostProperties()->getConnectionType()->value());
        $vmDefinition->setName($this->getContext()->getName());
        $vmDefinition->setNumCpu($vmSettings->getCpuCount());
        $vmDefinition->setRamMib($vmSettings->getRam());
        $vmDefinition->setSuspendEnabled(false);
        $vmDefinition->setHibernateEnabled(false);

        if ($guestOs->getOsFamily() === OsFamily::WINDOWS()) {
            $vmDefinition->setClockOffset(VmDefinition::CLOCK_OFFSET_LOCALTIME);
        }

        $vmDefinition->setArch(VmDefinition::ARCH_64);
    }
}
