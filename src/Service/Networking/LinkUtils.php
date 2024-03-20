<?php

namespace Datto\Service\Networking;

use Datto\Device\Serial;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Network\NetworkManager\NetworkConnection;
use Datto\Utility\Network\NetworkManager\NetworkManager;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * This class contains high-level utility/helper functions that are common to link operations on a SIRIS device.
 */
class LinkUtils implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private NetworkManager $networkManager;
    private Serial $serial;

    public function __construct(
        NetworkManager $networkManager,
        Serial $serial
    ) {
        $this->networkManager = $networkManager;
        $this->serial = $serial;
    }

    /**
     * Gets a bridge name, based on the name of the device that the bridge is for.
     *
     * @param string $device
     * @return string
     */
    public function generateBridgeName(string $device): string
    {
        $device = str_replace(['eth', 'en'], '', $device);
        return 'br' . $device;
    }

    /**
     * Creates a new bridge and adds the given connection to the bridge.
     *
     * @param NetworkConnection $connection
     * @return NetworkConnection
     */
    public function createBridgeFor(NetworkConnection $connection): NetworkConnection
    {
        $name = $this->generateBridgeName($connection->getDeviceName());
        $bridge = $this->networkManager->createBridge($name, $name);
        $connection->addToBridge($name);

        // Apply the default bridge configuration
        $bridge->setBridgeStp(false);
        $bridge->disableIpv6();
        $bridge->setDhcp();

        return $bridge;
    }

    /**
     * Activates a link by ensuring that all connections in the link are configured to automatically connect,
     * as well as calling activate on the top-most connection in the link, which will apply any changes made
     * to any of the connections to the running state.
     *
     * @param NetworkConnection $top The top-most connection in the link (the one with the IP configuration)
     */
    public function activateLink(NetworkConnection $top): void
    {
        $connections = $this->getHierarchy($top);

        // Set autoconnect on every related connection in the hierarchy. This appears to reliably work around
        // https://gitlab.freedesktop.org/NetworkManager/NetworkManager/-/issues/911
        foreach ($connections as $connection) {
            $connection->setAutoconnect(true);
        }

        // Explicitly activate the top-most connection, which will apply all the config from the whole stack
        $top->activate();
    }

    /**
     * Gets the topmost connection in a connection hierarchy, given any connection in that hierarchy. If this
     * connection already is the top-level, it will simply be returned. If the hierarchy is broken (e.g. a connection
     * refers to a master device that does not have an associated connection), the topmost valid connection in the
     * hierarchy will be returned.
     *
     * @param NetworkConnection $connection
     * @return NetworkConnection
     */
    public function getTopLevelConnection(NetworkConnection $connection): NetworkConnection
    {
        while ($master = $connection->getMaster()) {
            $masterConns = $this->networkManager->getConnectionsForInterface($master);
            $first = reset($masterConns);
            if (!$first) {
                break;
            }
            $connection = $first;
        }
        return $connection;
    }

    /**
     * Gets a collection of all the related connections (self and children) of a given connection. For example,
     * given a hierarchy that looks like the one below, calling with `bond0` would return eth0/eth1/bond0, while
     * calling with brbond0 would return all 4 connections.
     *
     * eth0 \
     *       - bond0 - brbond0
     * eth1 /
     *
     * @param NetworkConnection $top
     *
     * @return NetworkConnection[]
     */
    public function getHierarchy(NetworkConnection $top): array
    {
        $related = [$top];
        $children = $this->networkManager->getSlaveConnectionsForInterface($top->getDeviceName());
        foreach ($children as $child) {
            $related = array_merge($related, $this->getHierarchy($child));
        }
        return $related;
    }

    /**
     * Gets the connection that manages the physical interface associated with the given connection. For example, given
     * a bridge connection, this will find either the bridge, bond, or vlan that is configured as the bridge slave.
     *
     * If the connection passed to this function is *already* a physical connection (e.g. not a bridge), then it will
     * simply be returned.
     *
     * @param NetworkConnection $connection
     * @return NetworkConnection
     */
    public function getPhysicalConnection(NetworkConnection $connection): NetworkConnection
    {
        if ($connection->isBridge()) {
            $members = $this->networkManager->getSlaveConnectionsForInterface($connection->getDeviceName());
            if (empty($members)) {
                throw new RuntimeException('Could not find any members of bridge ' . $connection->getDeviceName());
            }

            if (count($members) > 1) {
                $this->logger->warning('LSV2001 Multiple configured slaves found for a single bridge.', [
                    'id' => $connection->getUuid(),
                    'iface' => $connection->getDeviceName(),
                    'name' => $connection->getName(),
                    'members' => array_map(fn($conn) => $conn->getName(), $members)
                ]);
            }

            // Grab the first member, since we don't currently have a better way of disambiguating them
            $member = reset($members);

            // Recursively call this, in case the returned connection is itself a non-physical one.
            return $this->getPhysicalConnection($member);
        } else {
            return $connection;
        }
    }

    /**
     * Gets the connection that is managing the device that is configured as parent to the given VLAN
     *
     * @param NetworkConnection $vlan
     * @return NetworkConnection
     */
    public function getVlanParent(NetworkConnection $vlan): NetworkConnection
    {
        if (!$vlan->isVlan()) {
            throw new RuntimeException('Connection is not a VLAN: ' . $vlan->getDeviceName());
        }
        $parentConnections = $this->networkManager->getConnectionsForInterface($vlan->getVlanDevice());
        /** @var NetworkConnection $conn */
        if ($conn = reset($parentConnections)) {
            return $conn;
        }
        throw new RuntimeException('Could not get parent connection for VLAN: ' . $vlan->getDeviceName());
    }

    /**
     * Checks whether any of the connections given are parents of a VLAN connection
     *
     * @param NetworkConnection[] $connections
     * @return bool
     */
    public function connectionsContainVlans(array $connections): bool
    {
        // Get the list of devices that are VLAN parents
        $vlanConns = array_filter($this->networkManager->getAllConnections(), fn($conn) => $conn->isVlan());
        $vlanParentDevices = array_map(fn($conn) => $conn->getVlanDevice(), $vlanConns);

        // Get the list of devices associated with the given connections
        $devices = array_map(fn($conn) => $conn->getDeviceName(), $connections);

        // If there's overlap, then at least one of the connections has a VLAN defined on it.
        return !empty(array_intersect($devices, $vlanParentDevices));
    }

    /**
     * A helper method designed for bond creation and deletion, which can be given an array of connection identifiers,
     * and will return an associative array containing parameters necessary for bond creation and deletion.
     *
     * This will out of necessity perform some validation, such as ensuring that the connections given actually
     * exist, and also that none of them contain any VLAN connections, both of which are required for both creating
     * and deleting bonds.
     *
     * @param string[] $linkIds The identifiers for the links involved in the bond operation.
     * @param string|null $primaryLinkId The identifier of the primary link (if applicable)
     *
     * @return array{delete: array<NetworkConnection>, devices: array<string>, primary: string|null}
     *   'delete': The connections that can be deleted in order to perform this bond operation
     *   'devices': The names of the devices that will be freed up and should be used for the bond operation
     *   'primary': The name of the primary device (null if no `$primary` primary param is passed)
     */
    public function deduceBondParameters(array $linkIds, ?string $primaryLinkId = null): array
    {
        /** @var NetworkConnection[] $delete */
        $delete = [];
        foreach ($linkIds as $linkId) {
            $conn = $this->networkManager->getConnection($linkId);
            $hierarchy = $this->getHierarchy($this->getTopLevelConnection($conn));
            $delete = array_merge($hierarchy, $delete);
        }

        // Make sure that the list of connections does not have any associated VLANs
        if ($this->connectionsContainVlans($delete)) {
            // The front-end is looking for 'Vlan exists'. This exception must contain that substring.
            throw new RuntimeException('Cannot perform bond operation. Vlan exists.');
        }

        // Get the list of ethernet devices in the hierarchy. When creating a bond, these are the devices that will
        // be added to the resulting bond. When deleting a bond, these are the devices that need new connections.
        // to be created.
        $ethernetConns = array_values(array_filter($delete, fn($conn) => $conn->isEthernet()));
        $devices = array_map(fn($conn) => $conn->getDeviceName(), $ethernetConns);

        // If a primary link was specified, map it to a given device
        $primary = null;
        if ($primaryLinkId) {
            $primaryConn = $this->getPhysicalConnection($this->networkManager->getConnection($primaryLinkId));
            $primary = $primaryConn->getDeviceName();
            if (!in_array($primary, $devices)) {
                throw new RuntimeException('Bond primary not in member devices');
            }
        }

        // Return the associative array
        return [
            'delete' => $delete,
            'devices' => $devices,
            'primary' => $primary
        ];
    }

    /**
     * When creating a bond, there are scenarios where we need to ensure that the bond has the MAC address
     * of a specific one of its members. This function takes the list of devices that are going to be bonded,
     * and will return a MAC if we need to clone one of them.
     *
     * @param string[] $deviceNames The names of the bond member devices
     * @return string The MAC address for the new bond, or an empty string if mac cloning is not necessary
     */
    public function getBondClonedMac(array $deviceNames): string
    {
        $clone = '';

        // If one of the devices MAC address matches the device serial number, we need the resulting bond to clone
        // that MAC address, otherwise checkin will fail.
        $serial = strtolower($this->serial->get());
        foreach ($deviceNames as $deviceName) {
            $device = $this->networkManager->getDevice($deviceName);
            if ($serial === strtolower(str_replace(':', '', $device->getMacAddr()))) {
                $clone = $device->getMacAddr();
                break;
            }
        }

        return $clone;
    }

    /**
     * Gets the state of the connection, for display on the UI
     *
     * @param NetworkConnection $connection
     * @return string The connection state (disconnected, disabled, acquiring, active, unknown)
     */
    public function getState(NetworkConnection $connection): string
    {
        $device = $connection->getDevice();
        if ($device && !$device->getCarrier()) {
            $state = NetworkLink::STATE_DISCONNECTED;
        } elseif ($connection->isDisabled()) {
            $state = NetworkLink::STATE_DISABLED;
        } elseif ($connection->getActiveState() === 'activated') {
            $state = NetworkLink::STATE_ACTIVE;
        } else {
            $state = NetworkLink::STATE_ACQUIRING;
        }
        return $state;
    }
}
