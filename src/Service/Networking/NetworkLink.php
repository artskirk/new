<?php

namespace Datto\Service\Networking;

use Datto\Utility\Network\IpAddress;
use JsonSerializable;

/**
 * An abstracted data model for a Network Link on the SIRIS device. Links of this type don't map directly to
 * physical network interfaces, nor do they map directly to NetworkManager Connections, or any other Linux network
 * object. Instead, this data model class can incorporate options from multiple physical interfaces and/or connections
 *
 * For example, given a network layout like below:
 *    eth0 \
 *          - bond0 - brbond0
 *    eth1 /
 *
 * This class might hold the IP address and DNS settings from the top-level bridge (brbond0), the bond-mode from
 * the bond (bond0) and the MTU and link speed from the underlying ethernet interfaces (eth0/eth1)
 *
 * The primary goal of this class is to allow high-level information about the system networking status to be
 * passed around the system, without other classes needing to be aware of the underlying implementation details.
 */
class NetworkLink implements JsonSerializable
{
    public const MODE_DHCP = 'dhcp';
    public const MODE_DISABLED = 'disabled';
    public const MODE_LINKLOCAL = 'link-local';
    public const MODE_STATIC = 'static';

    public const STATE_ACQUIRING = 'acquiring';
    public const STATE_ACTIVE = 'active';
    public const STATE_DISABLED = 'disabled';
    public const STATE_DISCONNECTED = 'disconnected';
    public const STATE_UNKNOWN = 'unknown';

    /** @var string The internal identifier for this Link. NOT for display on the UI */
    private string $id;

    /** @var string The UI-facing name of this Link */
    private string $name;

    /** @var string The state of this link (disconnected, disabled, acquiring, active, unknown) */
    private string $state = self::STATE_UNKNOWN;

    /** @var bool Whether this link has an active carrier (e.g. a cable is plugged in) */
    private bool $carrier = false;

    /** @var string The IPv4 Mode for this Link (disabled, static, DHCP) */
    private string $ipMode = self::MODE_DISABLED;

    /** @var IpAddress|null The IP Address currently associated with this Link */
    private ?IpAddress $ipAddress = null;

    /** @var IpAddress|null The default gateway for this Link, if any */
    private ?IpAddress $ipGateway = null;

    /** @var bool Whether this Link is the default (owns the current default route with lowest metric) */
    private bool $ipDefault = false;

    /** @var string The Hardware MAC address of this Link */
    private string $mac = '';

    /** @var bool Whether this Link is configured for jumbo frame support */
    private bool $jumboFrames = false;

    /** @var int The Layer-2 link speed (in Mbps) for this Link */
    private int $linkSpeed = 0;

    /** @var string The name of the underlying bridge interface, if this connection supports bridging */
    private string $bridgeIface = '';

    /** @var string If this Link represents a network bond, this is the bond mode. */
    private string $bondMode = '';

    /** @var string[] If this Link represents a network bond, these are the UI names of the bonded interfaces */
    private array $bondMemberNames = [];

    /** @var string|null If bondMode is 'active-backup', this is the primary interface */
    private ?string $bondPrimary = null;

    /** The vlan id */
    private ?int $vlanId = null;

    /**
     * Construct a NetworkLink with a given ID
     *
     * @param string $id
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getIpDefault(): bool
    {
        return $this->ipDefault;
    }

    /**
     * @return bool
     */
    public function hasCarrier(): bool
    {
        return $this->carrier;
    }

    /**
     * @return string
     */
    public function getIpMode(): string
    {
        return $this->ipMode;
    }

    /**
     * @return IpAddress|null
     */
    public function getIpAddress(): ?IpAddress
    {
        return $this->ipAddress;
    }

    /**
     * @return IpAddress|null
     */
    public function getIpGateway(): ?IpAddress
    {
        return $this->ipGateway;
    }

    /**
     * @return bool
     */
    public function isDefault(): bool
    {
        return $this->ipDefault;
    }

    /**
     * @return string
     */
    public function getMac(): string
    {
        return $this->mac;
    }

    /**
     * @return bool
     */
    public function isJumbo(): bool
    {
        return $this->jumboFrames;
    }

    /**
     * @return int
     */
    public function getLinkSpeed(): int
    {
        return $this->linkSpeed;
    }

    /**
     * @return string
     */
    public function getBridgeInterface(): string
    {
        return $this->bridgeIface;
    }

    /**
     * @return string
     */
    public function getBondMode(): string
    {
        return $this->bondMode;
    }

    /**
     * @return string[]
     */
    public function getBondMemberNames(): array
    {
        return $this->bondMemberNames;
    }

    /**
     * @return string|null
     */
    public function getBondPrimary(): ?string
    {
        return $this->bondPrimary;
    }

    /**
     * Sets the MAC address for this Link
     *
     * @param string $mac
     */
    public function setMac(string $mac)
    {
        $this->mac = $mac;
    }

    /**
     * Sets whether or not jumbo frames are enabled for this Link
     *
     * @param bool $enabled
     */
    public function setJumboFrames(bool $enabled)
    {
        $this->jumboFrames = $enabled;
    }

    /**
     * Sets the link speed (in Mbps) for this link. 0 for Unknown.
     *
     * @param int $linkSpeed
     */
    public function setLinkSpeed(int $linkSpeed)
    {
        $this->linkSpeed = $linkSpeed;
    }

    /**
     * @param string $name
     */
    public function setName(string $name)
    {
        $this->name = $name;
    }

    /**
     * @param string $state
     */
    public function setState(string $state)
    {
        $this->state = $state;
    }

    /**
     * @param bool $carrier
     * @return void
     */
    public function setCarrier(bool $carrier)
    {
        $this->carrier = $carrier;
    }

    /**
     * @param string $bridgeIface
     */
    public function setBridgeInterface(string $bridgeIface): void
    {
        $this->bridgeIface = $bridgeIface;
    }

    /**
     * Sets the IPv4 parameters for this Link.
     *
     * @param string $mode The current configured mode of this Link (dhcp, static, disabled).
     * @param IpAddress|null $address
     * @param IpAddress|null $gateway
     * @param bool $default
     */
    public function setIpv4Parameters(
        string $mode,
        ?IpAddress $address = null,
        ?IpAddress $gateway = null,
        bool $default = false
    ) {
        $this->ipMode = $mode;
        $this->ipAddress = $address;
        $this->ipGateway = $gateway;
        $this->ipDefault = $default;
    }

    /**
     * @param string $mode The bond mode
     * @param string[] $memberNames The UI-facing names of the bond members
     * @param string|null $primary The primary interface for bondmode 'active-backup'
     */
    public function setBondParameters(string $mode, array $memberNames, ?string $primary = null)
    {
        $this->bondMode = $mode;
        $this->bondMemberNames = $memberNames;
        $this->bondPrimary = $primary;
    }

    public function getVlanId(): ?int
    {
        return $this->vlanId;
    }

    public function setVlanId(?int $vlanId): void
    {
        $this->vlanId = $vlanId;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $iface = [
            'id' => $this->id,
            'name' => $this->name,
            'state' => $this->state,
            'carrier' => $this->carrier,
            'bridge' => $this->bridgeIface,
            'mac' => $this->mac,
            'jumboFrames' => $this->jumboFrames,
            'speed' => $this->linkSpeed
        ];

        $iface['ipv4'] = [
            'mode' => $this->ipMode,
            'address' => $this->ipAddress ? $this->ipAddress->getAddr() : '',
            'netmask' => $this->ipAddress ? $this->ipAddress->getMask() : '',
            'gateway' => $this->ipGateway ? $this->ipGateway->getAddr() : '',
            'default' => $this->ipDefault
        ];

        // If we have a VID, add it to the array
        if ($this->vlanId != null) {
            $iface['vid'] = $this->vlanId;
        }

        // If we have bond parameters, add them to the array
        if ($this->bondMode) {
            $iface['bond'] ['members'] = $this->bondMemberNames;
            $iface['bond'] ['mode'] = $this->bondMode;
            if ($this->bondPrimary != null) {
                $iface['bond']['primary'] = $this->bondPrimary;
            }
        }

        return $iface;
    }
}
