<?php

namespace Datto\Connection\Libvirt;

use Datto\Connection\ConnectionType;

/**
 * Represents local KVM hypervisor connection.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 *
 * @see AbstractLibvirtConnection
 */
class KvmConnection extends AbstractLibvirtConnection
{
    const CONNECTION_NAME = 'Local KVM';

    public function __construct()
    {
        parent::__construct(ConnectionType::LIBVIRT_KVM(), self::CONNECTION_NAME);

        // yep that's just that.
        $this->connectionData = array(
            'uri' => 'qemu:///system',
        );
    }

    protected function buildUri()
    {
        $this->uri = $this->getKey('uri');
    }

    public function isValid()
    {
        return true;
    }

    public function getHost(): string
    {
        // Until we start supporting remote KVM connections, this will always be the device's loopback address
        return '127.0.0.1';
    }

    public function supportsVnc(): bool
    {
        return true;
    }

    public function getRemoteConsoleInfo(string $vmName): ?AbstractRemoteConsoleInfo
    {
        return new RemoteVnc(
            $this->getHost(),
            null
        );
    }
}
