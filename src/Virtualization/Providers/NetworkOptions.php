<?php

namespace Datto\Virtualization\Providers;

use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Service\Networking\LinkService;
use Datto\Virtualization\EsxNetworking;
use Datto\Virtualization\Libvirt\Libvirt;

/**
 * Provides network options for various types of virtualization.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 *
 * @todo Fix this when Virtualization API calls are refactored (read below)
 *  This class is currently used inside sirisInfoBlock.php which is used by our API calls and returns HTML. When
 *  refactoring API calls we want to remove hardcoded English strings, return JSON,  and use translations instead.
 */
class NetworkOptions
{
    // bridged to adapter
    const NETWORK_BRIDGED = 'BRIDGE-%s';
    // firewalled with internet access
    const NETWORK_NAT = 'NAT';
    // firewalled with no internet access
    const NETWORK_INTERNAL = 'INTERNAL';
    // connected via VPN to local network
    const NETWORK_VPN = 'VPN';
    // disconnected
    const NETWORK_NONE = 'NONE';

    private LinkService $linkService;

    public function __construct(LinkService $linkService)
    {
        $this->linkService = $linkService;
    }

    /**
     * @param AbstractLibvirtConnection $connection
     * @return string[]
     */
    public function getSupportedNetworkModes(AbstractLibvirtConnection $connection)
    {
        $nicModes = $this->getSupportedNetworkModesWithDescriptions($connection);
        return array_keys($nicModes);
    }

    /**
     * @param AbstractLibvirtConnection $connection
     * @return array<string,string> internal name => user-friendly label
     */
    public function getSupportedNetworkModesWithDescriptions(AbstractLibvirtConnection $connection)
    {
        if ($connection->isEsx()) {
            /** @var EsxConnection $connection */
            $esxNet = new EsxNetworking($connection);
            $nicModes = $this->getEsxHypervisor($esxNet);
        } elseif ($connection->isHyperV()) {
            $nicModes = $this->getHvHypervisor($connection->getLibvirt());
        } else {
            $nicModes = $this->getLocal();
        }

        return $nicModes;
    }

    /**
     * Gets the networking options for virtualization via ESX hypervisor.
     *
     * For ESX, we need to bridge to a vSwitch portgroup and this is the only
     * possible option.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param EsxNetworking $esxNet
     *
     * @return array<string,string> internal name => user-friendly label
     */
    public function getEsxHypervisor(EsxNetworking $esxNet)
    {
        $nicModes = [];
        $networks = $esxNet->getHostVmNetworkList();

        foreach ($networks as $net) {
            $key = sprintf(self::NETWORK_BRIDGED, $net->name);
            $nicModes[$key] = $net->name;
        }

        $nicModes[self::NETWORK_NONE] = 'Disconnected';

        return $nicModes;
    }

    /**
     * Get the networking options for virtualization via Hyper-V.
     *
     * For Hyper-V we need to bridge to a vSwitch and that is the only possible
     * option.
     *
     * @param Libvirt $libvirt
     *
     * @return array
     */
    public function getHvHypervisor(Libvirt $libvirt)
    {
        $networks = $libvirt->hostGetAllNetworks();

        if ($networks) {
            foreach ($networks as $net) {
                $name = $libvirt->networkGetName($net);
                $uuid = $libvirt->networkGetUUIDString($net);

                $key = sprintf(self::NETWORK_BRIDGED, $uuid);
                $nicModes[$key] = $name;
            }
        }

        $nicModes[self::NETWORK_NONE] = 'Disconnected';

        return $nicModes;
    }

    /**
     * Gets the networking options for local virtualization.
     *
     * @return array
     */
    public function getLocal(): array
    {
        // TODO: return translation keys here instead
        $interfaces = $this->getNicModesWithIps();
        $interfaces[self::NETWORK_NAT] = 'Firewalled on Private Subnet';
        $interfaces[self::NETWORK_INTERNAL] = 'Firewalled on Private Subnet, with no internet access';
        $interfaces[self::NETWORK_NONE] = 'Disconnected';

        return $interfaces;
    }

    private function getNicModesWithIps(): array
    {
        // Get the list of links, and add an entry to the list for each one with a bridge defined.
        $nicModes = [];
        $links = $this->linkService->getLinks();
        foreach ($links as $link) {
            if ($link->getBridgeInterface()) {
                $ip = $link->getIpAddress();
                $interfaceValue = sprintf('Bridged to %s, %s', $link->getName(), $ip ? $ip->getCidr() : 'inactive');
                $interfaceKey = sprintf(self::NETWORK_BRIDGED, $link->getBridgeInterface());
                $nicModes[$interfaceKey] = $interfaceValue;
            }
        }
        ksort($nicModes);
        return $nicModes;
    }
}
