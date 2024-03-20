<?php

namespace Datto\Utility\Network;

use Datto\Common\Resource\ProcessFactory;

/**
 * A simple wrapper around network interface operations and status.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class IpInterface
{
    /** @var string The name of the interface (e.g. 'eth0', 'br1') */
    private string $name;

    /** @var array JSON information returned from `ip -json -details addr show <ifname>` */
    private array $details;

    /** @var ProcessFactory */
    private ProcessFactory $processFactory;

    public function __construct(
        string $name,
        ProcessFactory $processFactory
    ) {
        $this->name = $name;
        $this->processFactory = $processFactory;

        // Refresh the interface with full details
        $this->refresh();
    }

    /**
     * Refresh the network interface information, updating this object from the OS
     */
    public function refresh()
    {
        $output = $this->processFactory
            ->get(['ip', '-json', '-details', 'addr', 'show', $this->name])
            ->mustRun()
            ->getOutput();
        $json = json_decode($output, true);
        $this->details = (array) array_shift($json);
    }

    /**
     * Gets the name of this network interface
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the MAC address of this interface
     * @return string
     */
    public function getMac(): string
    {
        return $this->details['address'] ?? 'unknown';
    }

    /**
     * Determine if this is a loopback interface
     * @return bool
     */
    public function isLoopback(): bool
    {
        return ($this->details['link_type'] ?? '') === 'loopback';
    }

    /**
     * Determine if the interface is currently up
     * @return bool
     */
    public function isUp(): bool
    {
        return $this->details['operstate'] === 'UP';
    }

    /**
     * Determine if this is an ethernet interface. This does not not necessarily imply an interface
     * corresponds to a physical NIC, since bridges and bonds are still ethernet.
     * @see IpInterface::isPhysical() to determine if this is a physical (hardware) interface
     * @return bool
     */
    public function isEthernet(): bool
    {
        return $this->details['link_type'] === 'ether';
    }

    /**
     * Get the kind of interface as reported by ip (e.g. 'bridge', 'bond', 'vlan'). In the details structure
     * an unpopulated linkinfo.info_kind corresponds to a physical/hardware interface.
     * @return string
     */
    public function getKind(): string
    {
        if ($this->isLoopback()) {
            return 'loopback';
        }
        return $this->details['linkinfo']['info_kind'] ?? 'physical';
    }

    /**
     * Determine if an interface is a physical interface corresponding to actual system hardware
     * @return bool
     */
    public function isPhysical(): bool
    {
        return $this->isEthernet() && ($this->getKind() === 'physical');
    }

    /**
     * Determine if this interface is a network bridge
     * @return bool
     */
    public function isBridge(): bool
    {
        return $this->getKind() === 'bridge';
    }

    /**
     * Determine if this interface is a network bond
     * @return bool
     */
    public function isBond(): bool
    {
        return $this->getKind() === 'bond';
    }

    /**
     * Get the names of the interfaces that are members of this interface. If this interface
     * is not a network bridge or a network bond, this will return an empty array.
     * @return string[]
     */
    public function getMembers(): array
    {
        // Unfortunately, even with the detailed output, there's no way to actually see bridge/bond
        // members when looking at a single interface. Instead we use `ip` to query for interfaces
        // who have this interface set as their 'master'
        $members = [];
        if ($this->isBridge() || $this->isBond()) {
            $output = $this->processFactory
                ->get(['ip', '-json', 'link', 'show', 'master', $this->name])
                ->mustRun()
                ->getOutput();
            $members = array_map(fn($j) => $j['ifname'], json_decode($output, true));
        }
        return $members;
    }

    /**
     * Determine if this interface is a member of a network bridge
     * @return bool
     */
    public function isBridgeMember(): bool
    {
        return ($this->details['linkinfo']['info_slave_kind'] ?? '') === 'bridge';
    }

    /**
     * Determine if this interface is a member of a network bond
     * @return bool
     */
    public function isBondMember(): bool
    {
        return ($this->details['linkinfo']['info_slave_kind'] ?? '') === 'bond';
    }

    /**
     * Get the name of the bridge or bond interface that this interface is a member of.
     * @return string
     */
    public function getMemberOf(): string
    {
        if ($this->isBridgeMember() || $this->isBondMember()) {
            return $this->details['master'];
        }
        return '';
    }

    /**
     * Get the IP addresses associated with this network interface
     *
     * @return IpAddress[]
     */
    public function getAddresses(): array
    {
        $addr_infos = $this->details['addr_info'] ?? [];

        $addrs = [];
        foreach ($addr_infos as $addr_info) {
            // Skip IPv6 Addresses (Not currently supported)
            if ($addr_info['family'] !== 'inet') {
                continue;
            }
            $addr = IpAddress::fromAddr($addr_info['local'], $addr_info['prefixlen'], $addr_info['label'] ?? '');
            if ($addr !== null) {
                $addrs[] = $addr;
            }
        }
        return $addrs;
    }

    /**
     * Gets the first address associated with this network interface
     *
     * @return IpAddress|null
     */
    public function getFirstAddress(): ?IpAddress
    {
        $addrs = $this->getAddresses();
        return array_shift($addrs);
    }

    /**
     * Up the interface
     */
    public function setUp(): void
    {
        $this->processFactory
            ->get(['ip', 'link', 'set', $this->name, 'up'])
            ->mustRun();
    }

    /**
     * Down the interface
     */
    public function setDown(): void
    {
        $this->processFactory
            ->get(['ip', 'link', 'set', $this->name, 'down'])
            ->mustRun();
    }

    /**
     * Removes interface from bridge
     */
    public function removeFromBridge(): void
    {
        $this->processFactory
            ->get(['ip', 'link', 'set', $this->name, 'nomaster'])
            ->mustRun();
    }

    /**
     * Sets master device of this interface
     * @param string $master the master device
     */
    public function addToBridge(string $master): void
    {
        $this->processFactory
            ->get(['ip', 'link', 'set', $this->name, 'master', $master])
            ->mustRun();
    }
}
