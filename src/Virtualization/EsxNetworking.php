<?php
namespace Datto\Virtualization;

use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\EsxHostType;
use Vmwarephp\ManagedObject;
use Vmwarephp\Vhost;

/**
 * Provides methods to query/manage networking on ESX.
 */
class EsxNetworking
{
    private EsxConnection $connection;

    private ?Vhost $vhost = null;

    /**
     * @param EsxConnection $connection
     */
    public function __construct(EsxConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Gets all VM networks belonging to ESX host.
     *
     * If privided ESX connection points at cluster, the network list
     * returned will be for just single ESX host.
     *
     * @return ManagedObject[]
     */
    public function getHostVmNetworkList(): array
    {
        $vhost = $this->getVhost();

        if ($this->connection->getHostType() === EsxHostType::STANDALONE) {
            return $this->getAllVmNetworks();
        }

        $hosts = $vhost->findAllManagedObjects('HostSystem', ['name', 'network']);

        foreach ($hosts as $host) {
            if ($host->name === $this->connection->getEsxHost()) {
                return $host->network;
            }
        }

        return [];
    }

    /**
     * Gets all networks on the host regardless of host type.
     *
     * @return ManagedObject[]
     */
    private function getAllVmNetworks(): array
    {
        $vhost = $this->getVhost();

        return $vhost->findAllManagedObjects('Network', ['name']);
    }

    /**
     * Enable the ESX firewall ruleset for network-backed (Network) virtual serial ports
     *
     * Without the "remoteSerialPort" firewall ruleset enabled, the ESX firewall
     * will block traffic associated with the serial port.
     *
     * Refer to the VMware documentation for the required firewall rule set for
     * virtual serial ports connected over a network:
     * {@link http://pubs.vmware.com/vsphere-60/index.jsp#com.vmware.vsphere.vm_admin.doc/GUID-09086DBC-FC86-4589-9B58-3310B2FB47A8.html}
     */
    public function enableSerialPortFirewallRuleset(): void
    {
        $vhost = $this->getVhost();
        $hostSystem = null;

        if ($this->connection->isStandalone()) {
            $hosts = $vhost->findAllManagedObjects('HostSystem', []);
            if (count($hosts) === 1) {
                $hostSystem = $hosts[0];
            }
        } else {
            $allHostSystems = $vhost->findAllManagedObjects('HostSystem', []);

            $hostSystemName = $this->connection->getEsxHost();
            $systemsMatchingHost = array_filter(
                $allHostSystems,
                fn (ManagedObject $host) => $host->name === $hostSystemName
            );
            if ($systemsMatchingHost) {
                $hostSystem = end($systemsMatchingHost);
            }
        }

        if ($hostSystem === null) {
            throw new \Exception("Could not locate ESX host");
        }

        $hostSystem->configManager->firewallSystem->EnableRuleset(['id' => 'remoteSerialPort']);
    }

    /**
     * Returns a Vhost singleton.
     *
     * @psalm-suppress PossiblyNullArgument
     */
    private function getVhost(): Vhost
    {
        return $this->vhost ??= new Vhost(
            $this->connection->getPrimaryHost(),
            $this->connection->getUser(),
            $this->connection->getPassword()
        );
    }
}
