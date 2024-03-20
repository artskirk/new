<?php

namespace Datto\Virtualization;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Service\Security\FirewallService;
use Datto\Virtualization\EsxNetworking;
use Datto\Log\DeviceLoggerInterface;
use RuntimeException;

/**
 * Factory for creating Local and Remote Virtual Machine objects
 *
 * @author Brian Grogan <bgrogan@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class VirtualMachineFactory
{
    private Filesystem $filesystem;

    private Sleep $sleep;

    private FirewallService $firewallService;

    public function __construct(
        Filesystem $filesystem,
        Sleep $sleep,
        FirewallService $firewallService
    ) {
        $this->filesystem = $filesystem;
        $this->sleep = $sleep;
        $this->firewallService = $firewallService;
    }

    /**
     * Create a VirtualMachine instance
     *
     * @param VmInfo $vmInfo
     * @param string $storageDir
     * @param AbstractLibvirtConnection $connection
     * @param DeviceLoggerInterface $logger
     * @return VirtualMachine
     */
    public function create(
        VmInfo $vmInfo,
        string $storageDir,
        AbstractLibvirtConnection $connection,
        DeviceLoggerInterface $logger
    ): VirtualMachine {
        switch ($connection->getType()) {
            case ConnectionType::LIBVIRT_KVM():
                return new LocalVirtualMachine(
                    $vmInfo->getName(),
                    $vmInfo->getUuid(),
                    $storageDir,
                    $connection,
                    $this->filesystem,
                    $this->sleep,
                    $logger,
                    $this->firewallService
                );
            case ConnectionType::LIBVIRT_ESX():
                /** @var EsxConnection $connection */
                return new EsxVirtualMachine(
                    $vmInfo->getName(),
                    $vmInfo->getUuid(),
                    $storageDir,
                    $connection,
                    $this->filesystem,
                    $this->sleep,
                    new EsxNetworking($connection),
                    $logger,
                    $this->firewallService
                );
            case ConnectionType::LIBVIRT_HV():
                return new HvVirtualMachine(
                    $vmInfo->getName(),
                    $vmInfo->getUuid(),
                    $storageDir,
                    $connection,
                    $this->filesystem,
                    $this->sleep,
                    $logger,
                    $this->firewallService
                );
            default:
                throw new RuntimeException("ConnectionType '{$connection->getType()}' not supported");
        }
    }
}
