<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmInputDefinition;

/**
 * Usb tablet input builder
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class UsbTabletInputBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        // Attach a USB Tablet for absolute cursor positioning in RDP/VNC.
        $usbTabletInputDefinition = new VmInputDefinition(
            VmInputDefinition::TYPE_TABLET,
            VmInputDefinition::BUS_USB
        );
        $vmDefinition->getInputDevices()->append($usbTabletInputDefinition);
    }
}
