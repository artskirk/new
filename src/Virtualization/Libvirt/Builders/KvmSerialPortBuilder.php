<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmSerialPortDefinition;
use Datto\Virtualization\LocalVirtualMachine;

/**
 * Serial port builder for KVM Hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class KvmSerialPortBuilder extends BaseVmDefinitionBuilder
{
    /**
     * @const string
     *   Format string for building VM serial port socket paths.
     *
     * For example, the first serial port for a VM named 'foo' would use:
     *   * /tmp/lakitu
     *   * foo
     *   * 1
     * ...to build:
     *   /var/lib/datto/inspection/socket/foo.com1
     */
    const SOCKET_PATH_FORMAT = '%s/%s.com%d';

    private const UNIX_SOCKET_MAX_LENGTH = 108 - 1; // - 1 for null terminator

    /**
     * @inheritdoc
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmSerialPort = new VmSerialPortDefinition();
        $vmSerialPort->setPort(VmSerialPortDefinition::COM_1);

        // The socket path must be less than or equal to 107 characters. Cut off the front of the vm name if necessary
        // to make the path fit into 107 characters.
        $maxVmNameLength = self::UNIX_SOCKET_MAX_LENGTH - strlen($this->getSocketPath(''));
        $vmName = substr($this->getContext()->getName(), -$maxVmNameLength);
        $socketPath = $this->getSocketPath($vmName);

        $vmSerialPort->setType(VmSerialPortDefinition::TYPE_UNIX);
        $vmSerialPort->setPath($socketPath);
        $vmDefinition->getSerialPorts()->append($vmSerialPort);
    }

    private function getSocketPath(string $vmName): string
    {
        return sprintf(
            self::SOCKET_PATH_FORMAT,
            LocalVirtualMachine::SOCKET_DIR,
            $vmName,
            VmSerialPortDefinition::COM_1
        );
    }
}
