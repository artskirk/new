<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmSerialPortDefinition;
use RuntimeException;

/**
 * Serial port builder for ESX Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxSerialPortBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $host = $this->getContext()->getVmHostProperties()->getHost();

        if (empty($host)) {
            throw new RuntimeException('Expected a non empty value for Host');
        }

        $vmSerialPort = new VmSerialPortDefinition();
        $vmSerialPort->setPort(VmSerialPortDefinition::COM_1);
        $vmSerialPort->setType(VmSerialPortDefinition::TYPE_TCP);
        $vmSerialPort->setHost($host);
        $vmDefinition->getSerialPorts()->append($vmSerialPort);
    }
}
