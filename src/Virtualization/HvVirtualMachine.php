<?php

namespace Datto\Virtualization;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;
use Datto\Service\Security\FirewallService;

/**
 * Virtual Machine using Hyper-V hypervisor
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class HvVirtualMachine extends RemoteVirtualMachine
{
    /**
     * @param string $name
     * @param string $uuid
     * @param string $storageDir
     * @param AbstractLibvirtConnection $connection
     * @param Filesystem $filesystem
     * @param Sleep $sleep
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        string $name,
        string $uuid,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        Filesystem $filesystem,
        Sleep $sleep,
        DeviceLoggerInterface $logger,
        FirewallService $firewallService
    ) {
        $this->assertConnectionType(ConnectionType::LIBVIRT_HV(), $connection->getType());
        parent::__construct($name, $uuid, $storageDir, $connection, $filesystem, $sleep, $logger, $firewallService);
    }
}
