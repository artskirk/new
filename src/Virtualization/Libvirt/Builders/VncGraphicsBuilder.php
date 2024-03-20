<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmGraphicsDefinition;
use RuntimeException;

/**
 * VNC Graphics builder
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VncGraphicsBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vncPort = $this->getContext()->getVncPort();
        $vncPassword = $this->getContext()->getVncPassword();

        if (empty($vncPort) || empty($vncPassword)) {
            throw new RuntimeException('Expected non empty values for VncPort and VncPassword');
        }

        // add VNC output first so that virt-manager always shows pictures
        $vmGraphicsVnc = new VmGraphicsDefinition();
        $vmGraphicsVnc->setType(VmGraphicsDefinition::TYPE_VNC);
        $vmGraphicsVnc->setPort($vncPort);
        $vmGraphicsVnc->setPassword($vncPassword);
        $vmGraphicsVnc->setListen(
            [
            'type' => 'address',
            'address' => '0.0.0.0'
            ]
        );

        $vmDefinition->getGraphicsAdapters()->append($vmGraphicsVnc);
    }
}
