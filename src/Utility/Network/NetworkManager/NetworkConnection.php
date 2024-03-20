<?php

namespace Datto\Utility\Network\NetworkManager;

use Datto\Utility\Network\CachingNmcli;
use Datto\Utility\Network\IpAddress;

/**
 * This class represents a single Connection, and provides convenience APIs for reading and manipulating its
 * properties and state.
 * In NetworkManager, a Connection defines the configuration for a single device or interface (NM uses the two
 * terms somewhat interchangeably). The connection is essentially a profile defining the desired device configuration,
 * and multiple profiles for a single device/interface can exist, usually with a limit of one active connection per
 * device at any given time.
 *
 * Lower-case properties are defined in the connection, and can be seen as "configuration", while upper-case properties
 * are interpreted as "state" when a connection is activated. For example, `ipv4.dns` will show any explicitly-
 * configured nameservers, while `IP4.DNS[1]` will show the first currently-configured nameserver (e.g. in the case
 * of a DNS nameserver supplied via DHCP).
 *
 * Essentially, lower-case settings are stored in the backing file(s) in /etc/NetworkManager/system-connections/, while
 * upper-case values correspond to the values read from the kernel networking subsystem, and match the values
 * reported by tools like `ip` and `bridge`. For this reason, the majority of the APIs in this class deal with
 * lower-case network configuration management, not current state.
 *
 * @see https://networkmanager.dev/docs/api/latest/nm-settings-nmcli.html
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class NetworkConnection
{
    /** @var string A connection identifier. (UUID, Name, File Path, Active (DBUS) Path) */
    private string $identifier;

    private CachingNmcli $nmcli;
    private NetworkConnectionFactory $connectionFactory;

    public function __construct(
        string $identifier,
        CachingNmcli $nmcli,
        NetworkConnectionFactory $connectionFactory
    ) {
        $this->identifier = $identifier;
        $this->nmcli = $nmcli;
        $this->connectionFactory = $connectionFactory;
    }

    //****************************************************************
    // Getters - Connection Configuration
    //****************************************************************

    /**
     * Get the connection name (Note this is distinct from the underlying device/interface name)
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->getField('connection.id');
    }

    /**
     * Get the connection UUID
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->getField('connection.uuid');
    }

    /**
     * Get the name of the device/interface managed by this connection (e.g. `eth2`, `bond0`, `br0.100`)
     *
     * @return string
     */
    public function getDeviceName(): string
    {
        return $this->getField('connection.interface-name');
    }

    /**
     * Get the underlying NetworkDevice that this connection manages. In some cases, such as a VPN connection which
     * doesn't apply to a specific device, or a connection that refers to a non-existent device (e.g. if a NIC fails),
     * this will return null.
     *
     * @return NetworkDevice|null
     */
    public function getDevice(): ?NetworkDevice
    {
        return $this->connectionFactory->getDevice($this->getDeviceName());
    }

    /**
     * Whether this connection will auto-connect at system startup
     *
     * @return bool
     */
    public function isAutoconnect(): bool
    {
        return $this->getField('connection.autoconnect') === 'yes';
    }

    /**
     * Whether this connection is configured for DHCP
     *
     * @return bool
     */
    public function isDhcp(): bool
    {
        return $this->getField('ipv4.method') === 'auto';
    }

    /**
     * Whether this connection is configured for link-local addressing
     *
     * @return bool
     */
    public function isLinkLocal(): bool
    {
        return $this->getField('ipv4.method') === 'link-local';
    }

    /**
     * Whether this connection is disabled for IPv4
     *
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->getField('ipv4.method') === 'disabled';
    }

    /**
     * Get the collection of IPv4 Addresses configured for this connection. Empty if no static IPs are configured
     *
     * @return IpAddress[]
     */
    public function getIpAddresses(): array
    {
        $addrs = explode(',', $this->getField('ipv4.addresses'));
        if ($addrs) {
            return array_filter(array_map(fn($addr) => IpAddress::fromCidr(trim($addr)), $addrs));
        }
        return [];
    }

    /**
     * Gets the configured network gateway for this connection, or null if no gateway is set.
     *
     * @return IpAddress|null
     */
    public function getGateway(): ?IpAddress
    {
        return IpAddress::fromAddr($this->getField('ipv4.gateway'));
    }

    /**
     * Gets the DNS Nameserver addresses for this connection, if any are configured
     *
     * @return IpAddress[]
     */
    public function getDnsNameservers(): array
    {
        $addrs = explode(',', $this->getField('ipv4.dns'));
        if ($addrs) {
            return array_filter(array_map(fn($addr) => IpAddress::fromAddr(trim($addr)), $addrs));
        }
        return [];
    }

    /**
     * Gets an array of DNS Search Domains for this connection, if any are configured
     *
     * @return string[]
     */
    public function getDnsSearchDomains(): array
    {
        return array_filter(explode(',', $this->getField('ipv4.dns-search')));
    }

    /**
     * Get the type of this connection (e.g. 'bridge', 'bond', 'ethernet', 'vlan', 'vpn')
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->getField('connection.type');
    }

    /**
     * Get the MTU that this connection will configure the underlying device for
     *
     * @return int The MTU in bytes, or 0 if no explicit MTU is configured
     */
    public function getMtu(): int
    {
        return intval($this->getField('802-3-ethernet.mtu'));
    }

    /**
     * Get the cloned MAC address, if this connection has one specified
     *
     * @return string The cloned MAC address, or empty string if none is set
     */
    public function getClonedMac(): string
    {
        return $this->getField('802-3-ethernet.cloned-mac-address');
    }

    /**
     * Whether this connection is a basic ethernet connection (as opposed to a bridge, bond, vlan, vpn, wifi, etc...)
     *
     * @return bool
     */
    public function isEthernet(): bool
    {
        return $this->getType() === '802-3-ethernet';
    }

    /**
     * Get the name of the device/interface that this connection is enslaved to. Valid for both bridge- and bond-slaves
     *
     * @return string
     */
    public function getMaster(): string
    {
        return $this->getField('connection.master');
    }

    /**
     * Whether this connection defines a network bridge
     *
     * @return bool
     */
    public function isBridge(): bool
    {
        return $this->getType() === 'bridge';
    }

    /**
     * Whether this connection defines a member/slave of a network bridge
     *
     * @return bool
     */
    public function isBridgeMember(): bool
    {
        return $this->getField('connection.slave-type') === 'bridge';
    }

    /**
     * Whether this connection defines a network bond
     *
     * @return bool
     */
    public function isBond(): bool
    {
        return $this->getType() === 'bond';
    }

    /**
     * Whether this connection defines a member/slave of a network bond
     *
     * @return bool
     */
    public function isBondMember(): bool
    {
        return $this->getField('connection.slave-type') === 'bond';
    }

    /**
     * Get the bond options for this connection.
     *
     * @return array Associative array of bond options, in `['option' => 'value', 'option' => 'value']` format.
     */
    public function getBondOptions(): array
    {
        $options = [];
        $optionString = $this->getField('bond.options');
        foreach (explode(',', $optionString) as $option) {
            $split = explode('=', $option, 2);
            if ($split && count($split) === 2) {
                $options[$split[0]] = $split[1];
            }
        }
        return $options;
    }

    /**
     * Whether this connection defines a network VLAN
     *
     * @return bool
     */
    public function isVlan(): bool
    {
        return $this->getType() === 'vlan';
    }

    /**
     * Get the VLAN ID (VID, PVID) for this connection
     *
     * @return int
     */
    public function getVlanId(): int
    {
        return (int)$this->getField('vlan.id');
    }

    /**
     * Get the name of the underlying interface/device that this VLAN is attached to. For example, for a connection
     * managing the `br0.100` interface, this would return `br0`, while `getDevice()` would return `br0.100`.
     *
     * @return string
     */
    public function getVlanDevice(): string
    {
        return $this->getField('vlan.parent');
    }

    //****************************************************************
    // Getters - Status
    //****************************************************************

    /**
     * Whether this connection is currently active. Active here does not necessarily mean fully configured.
     * For example, a connection that is active might be waiting on a DHCP assignment, in which case it would not
     * have an IP address or be usable yet.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->getActiveState() !== '';
    }

    /**
     * Get the current active state of this connection. (unknown, activating, activated, deactivating, deactivated).
     * Will return an empty string on a connection that is totally inactive.
     *
     * @return string
     */
    public function getActiveState(): string
    {
        return $this->getField('GENERAL.STATE');
    }

    /**
     * Whether this connection currently holds the system default route (The default route with the lowest route
     * metric if multiple default routes exist).
     *
     * @return bool
     */
    public function isCurrentDefault(): bool
    {
        return $this->getField('GENERAL.DEFAULT') === 'yes';
    }

    /**
     * Get an array of the IP Addresses currently active on this connection
     *
     * @return IpAddress[]
     */
    public function getCurrentAddresses(): array
    {
        $addrs = explode('&', $this->getField('IP4.ADDRESS'));
        if ($addrs) {
            return array_filter(array_map(fn($addr) => IpAddress::fromCidr(trim($addr)), $addrs));
        }
        return [];
    }

    /**
     * Get the configured gateway IP for this connection, or null if no gateway is configured
     *
     * @return IpAddress|null
     */
    public function getCurrentGateway(): ?IpAddress
    {
        return IpAddress::fromAddr($this->getField('IP4.GATEWAY'));
    }

    //****************************************************************
    // Setters
    //****************************************************************

    /**
     * Set the name of this connection. Note that this is distinct from the name of the interface/device that this
     * connection manages.
     *
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->modify(['connection.id', $name]);
    }

    /**
     * Sets the name of the device that this connection manages.
     *
     * @param string $name
     */
    public function setDeviceName(string $name): void
    {
        $this->modify(['connection.interface-name', $name]);
    }

    /**
     * Configure this connection to autoconnect when the system starts.
     * @param bool $auto
     */
    public function setAutoconnect(bool $auto): void
    {
        $this->modify(['connection.autoconnect', $auto ? 'yes' : 'no']);
    }

    /**
     * Configure this connection to use DHCP for its IPv4 configuration
     */
    public function setDhcp(): void
    {
        $this->modify(['ipv4.address', '', 'ipv4.gateway', '', 'ipv4.method', 'auto']);
    }

    /**
     * Configure this connection to use a Link-Local address for its IPv4 Configuration
     */
    public function setLinkLocal(): void
    {
        $this->modify([
            'ipv4.address', '',
            'ipv4.gateway', '',
            'ipv4.dns', '',
            'ipv4.dns-search', '',
            'ipv4.method', 'link-local']);
    }

    /**
     * Configure this connection for a static IP address
     *
     * @param IpAddress $address The IP Address and Network Mask for this connection
     * @param IpAddress|null $gateway An optional gateway address for this connection
     */
    public function setStatic(IpAddress $address, ?IpAddress $gateway = null): void
    {
        $gw = $gateway ? $gateway->getAddr() : '';
        $this->modify(['ipv4.method', 'manual', 'ipv4.address', $address->getCidr(), 'ipv4.gateway', $gw ?? '']);
    }

    /**
     * Configure this connection to disable IPv4 addressing
     *
     * @return void
     */
    public function setDisabled(): void
    {
        $this->modify([
            'ipv4.address', '',
            'ipv4.gateway', '',
            'ipv4.dns', '',
            'ipv4.dns-search', '',
            'ipv4.method', 'disabled']);
    }

    /**
     * Configure this connection as manual, usually for the purposes of enslaving it to a bridge or bond
     */
    public function setManual(): void
    {
        $this->modify(['ipv4.address', '', 'ipv4.gateway', '', 'ipv4.method', 'manual']);
    }

    /**
     * Set the DNS Nameservers for this connection
     *
     * @param IpAddress[] $dnsServers DNS Nameservers that this connection will use for name resolution
     */
    public function setDns(array $dnsServers = []): void
    {
        $this->modify(['ipv4.dns', implode(',', array_map(fn($ip) => $ip->getAddr(), $dnsServers))]);
    }

    /**
     * Set the DNS Search Domains for this connection
     *
     * @param string[] $dnsSearch Search domains that this connection will use during name resolution
     */
    public function setDnsSearch(array $dnsSearch = []): void
    {
        $this->modify(['ipv4.dns-search', implode(',', $dnsSearch)]);
    }

    /**
     * Set the MTU of this connection.
     * @param int|null $mtuBytes
     */
    public function setMtu(?int $mtuBytes = null): void
    {
        $this->modify(['802-3-ethernet.mtu', $mtuBytes ?? 0]);
    }

    /**
     * Configure the route metric for this connection, which will determine the order it is used.
     *
     * @param int|null $metric The route metric, or null to return to the default value.
     */
    public function setMetric(?int $metric = null): void
    {
        $this->modify(['ipv4.route-metric', $metric ?? '']);
    }

    /**
     * Clear the explicit routes for this connection.
     */
    public function clearRoutes(): void
    {
        $this->modify(['ipv4.routes', '']);
    }

    /**
     * Add an explicit route to this connection
     *
     * @param IpAddress $dest The destination IP/Mask
     * @param IpAddress|null $gateway The optional gateway for this route
     */
    public function addRoute(IpAddress $dest, ?IpAddress $gateway = null): void
    {
        $routeStr = $dest->getCidr();
        if ($gateway) {
            $routeStr .= ' ' . $gateway->getAddr();
        }
        $this->modify(['+ipv4.routes', $routeStr]);
    }

    /**
     * Configure an explicit "MAC Override" for this connection's device. Useful to "hard-code" a MAC on
     * a device like a bond, which will otherwise have the MAC set from the first active slave, which is not
     * deterministic for every bond mode.
     *
     * @param string $macAddress MAC in colon-separated octet format ('01:02:03:04:05:06'), or an empty string to unset.
     */
    public function setClonedMac(string $macAddress = ''): void
    {
        $this->modify(['802-3-ethernet.cloned-mac-address', $macAddress]);
    }

    /**
     * Disables IPv6 support on this connection. Unfortunately, NetworkManager ignores the kernel settings
     * (set via sysctl), and IPv6 must be explicitly disabled on each connection. It does not appear that it is
     * possible to disable globally.
     */
    public function disableIpv6(): void
    {
        $this->modify(['ipv6.method', 'disabled']);
    }

    /**
     * Configure this connection to be enslaved to a bridge.
     *
     * @param string $bridgeIface The device/interface name of the bridge master (not the connection name)
     */
    public function addToBridge(string $bridgeIface): void
    {
        $this->modify(['connection.master', $bridgeIface, 'connection.slave-type', 'bridge']);
    }

    /**
     * Configure STP (Spanning Tree Protocol) on a bridge.
     * @param bool $stp
     */
    public function setBridgeStp(bool $stp): void
    {
        $this->modify(['bridge.stp', $stp ? 'yes' : 'no']);
    }

    /**
     * Configure the bridge forward-delay, one of the STP (Spanning Tree Protocol) parameters
     *
     * @param int|null $fd The forward-delay in seconds, or null to restore defaults
     */
    public function setBridgeFd(?int $fd): void
    {
        $this->modify(['bridge.forward-delay', $fd ?? '']);
    }

    /**
     * Configure this connection to be enslaved to a bond.
     *
     * @param string $bondIface The device/interface name of the bond master (not the connection name)
     */
    public function addToBond(string $bondIface): void
    {
        $this->modify(['connection.master', $bondIface, 'connection.slave-type', 'bond']);
    }

    /**
     * Configure the bond options for this connection.
     *
     * @param array $options Bond options in ['option' => 'value'] associative array format
     * @example
     * ```php
     *    $conn->setBondOptions(['bond-mode' => 'balance-rr', 'miimon' => 100]);
     * ```
     */
    public function setBondOptions(array $options): void
    {
        $optionString = implode(',', array_map(function ($option, $value) {
            return $option . '=' . $value;
        }, array_keys($options), array_values($options)));
        $this->modify(['bond.options', $optionString]);
    }

    /**
     * Sets the parent device for a given VLAN. Does not automatically change the connection or device names
     *
     * @param string $parent The name of the new parent device
     */
    public function setVlanParent(string $parent): void
    {
        $this->modify(['vlan.parent', $parent]);
    }

    /**
     * Sets the firewall zone of the connection.
     * @param string $zone
     */
    public function setFirewallZone(string $zone): void
    {
        $this->modify(['connection.zone', $zone]);
    }

    //****************************************************************
    // Connection Management
    //****************************************************************

    public function delete(): void
    {
        $this->nmcli->connectionDelete($this->identifier);
    }

    /**
     * Manually attempt to bring a connection up. If there are any other connections that manage the same
     * device, they will likely be brought down, since connections are (usually) mutually exclusive, except for
     * scenarios like VPN connections.
     *
     * @param int $wait The number of seconds to wait for this connection to activate. In the case of DHCP interfaces,
     *                  this means waiting for a lease to be obtained (and throwing an exception if one is not). A
     *                  wait time of 0 means that we will activate the connection, but not wait for it to complete
     *                  activation.
     */
    public function activate(int $wait = 0): void
    {
        $this->nmcli->connectionUp($this->identifier, $wait);
    }

    /**
     * Manually attempt to bring a connection down. If there are other connections for this same device that are
     * configured to autoconnect, this will cause them to attempt a connection.
     */
    public function deactivate(): void
    {
        $this->nmcli->connectionDown($this->identifier);
    }

    /**
     * Restarts a connection. Required after making configuration changes for them to take effect.
     */
    public function restart(): void
    {
        $this->deactivate();
        $this->activate();
    }

    //****************************************************************
    // Import/Export
    //****************************************************************

    /**
     * Exports the IP (ipv4/ipv6) Configuration from a connection into a structure that can be later imported
     * into another connection.
     *
     * @see importIpConfig
     *
     * @return array The exported IP configuration
     */
    public function exportIpConfig(): array
    {
        $config = [];
        foreach ($this->getFields() as $field => $value) {
            // We only want to export ipv4 and ipv6 config
            if (strncmp($field, 'ipv', 3) === 0) {
                // For some reason, on at least one setting (ipv6.ip6-privacy) when we read, nmcli reports '-1' for
                // default settings, but when we write, it won't accept '-1', but will accept '' as a 'default'
                // shorthand. There are no settings where '-1' is an actual acceptable non-default value, so this
                // should be perfectly fine to do.
                if ($value === '-1') {
                    $value = '';
                }

                $config[] = $field;
                $config[] = $value;
            }
        }
        return $config;
    }

    /**
     * Imports IP configuration from a previous export.
     *
     * @see exportIpConfig
     *
     * @param array $config
     * @return void
     */
    public function importIpConfig(array $config)
    {
        $this->modify($config);
    }

    //****************************************************************
    // Internal helper functions
    //****************************************************************

    /**
     * Get a single field from this connection, without escaping special characters (':' and '\')
     * @see https://networkmanager.dev/docs/api/latest/nm-settings-nmcli.html
     *
     * @param string $field The field to retrieve
     * @return string The value of the field or empty string if the field is empty
     */
    private function getField(string $field): string
    {
        return $this->getFields()[$field] ?? '';
    }

    /**
     * Gets all the fields for this connection, without escaping special characters (':' and '\')
     * @return array
     */
    private function getFields(): array
    {
        $connections = $this->nmcli->connectionShowDetails();

        foreach ($connections as $connectionFields) {
            $uuid = $connectionFields['connection.uuid'] ?? null;
            $name = $connectionFields['connection.id'] ?? null;
            $path = $connectionFields['GENERAL.CON-PATH'] ?? null;
            $dbusPath = $connectionFields['GENERAL.DBUS-PATH'] ?? null;

            if (in_array($this->identifier, [$uuid, $name, $path, $dbusPath], true)) {
                return $connectionFields;
            }
        }

        return [];
    }

    /**
     * Modify the value of one or more fields in a single transaction.
     *
     * @param array $fields Non-associative array containing fields and their values to set
     */
    private function modify(array $fields): void
    {
        $this->nmcli->connectionModify($this->identifier, $fields);
    }
}
