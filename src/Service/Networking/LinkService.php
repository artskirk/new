<?php

namespace Datto\Service\Networking;

use Datto\Common\Resource\Sleep;
use Datto\Log\LoggerAwareTrait;
use Datto\Security\AllowForwardForLinks;
use Datto\Utility\Network\IpAddress;
use Datto\Utility\Network\NetworkManager\NetworkManager;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;

/**
 * This class holds high-level functions for create/read/update/delete operations on Network Links.
 *
 * @see NetworkLink
 */
class LinkService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const MTU_STANDARD = 1500;
    private const MTU_JUMBO = 9000;
    private const BOND_NAME = 'bond0';
    private const SUPPORTED_BOND_MODES = ['balance-rr', 'active-backup', '802.3ad'];

    private NetworkManager $networkManager;
    private LinkBackup $linkBackup;
    private LinkUtils $linkUtils;
    private Sleep $sleep;
    private AllowForwardForLinks $allowForwardForLinks;

    public function __construct(
        NetworkManager $networkManager,
        LinkBackup $linkBackup,
        LinkUtils $linkUtils,
        Sleep $sleep,
        AllowForwardForLinks $allowForwardForLinks
    ) {
        $this->networkManager = $networkManager;
        $this->linkBackup = $linkBackup;
        $this->linkUtils = $linkUtils;
        $this->sleep = $sleep;
        $this->allowForwardForLinks = $allowForwardForLinks;
    }

    /**
     * Get all the active NetworkLinks on the system
     *
     * @return NetworkLink[]
     */
    public function getLinks(): array
    {
        $links = [];

        // Get all the Ethernet Devices that NetworkManager Manages
        foreach ($this->networkManager->getTopLevelConnections() as $connection) {
            $links[] = $this->getLinkById($connection->getUuid());
        }

        // Return the array, filtering out any null entries
        return array_filter($links);
    }

    /**
     * Add a vlan to the device.
     * @param string $parentLinkId the uuid to which the vlan is to be added.
     * @param int $vid the vlan id to be added.
     */
    public function addVlan(string $parentLinkId, int $vid): void
    {
        if ($vid < 1 || $vid > 4094) {
            throw new Exception('Invalid vid, should be in range 1-4094', 3);
        }

        $parentConn = $this->networkManager->getConnection($parentLinkId);
        if ($parentConn->isVlan()) {
            throw new Exception("$parentLinkId is a vlan, cannot add vlan to a vlan", 5);
        }

        // Creating a VLAN on a bridge can have some weird behavior when that bridge is modified and
        // reactivated later, so we create the VLAN on the underlying physical connection instead.
        // This also will avoid multiple bridges in a hierarchy if we ever need to create a bridge on
        // top of a VLAN (e.g. to attach a virt to it)
        $physical = $this->linkUtils->getPhysicalConnection($parentConn);
        $parentName = $physical->getDeviceName();
        $vlanName = $parentName . "." . $vid;

        $connections = $this->networkManager->getAllConnections();
        foreach ($connections as $connection) {
            if ($connection->isVlan() && $connection->getVlanId() === $vid &&
                $connection->getVlanDevice() === $parentName) {
                //This message is being keyed off by front end for error handling. Please don't change it.
                throw new Exception("This vlan already exists name: " . $connection->getDeviceName() .
                    " vid: " . $vid . " guid: " . $connection->getUuid(), 6);
            }
        }

        // Snapshot the current connection state before changing anything.
        $this->linkBackup->create();

        try {
            $this->logger->info('LSV1010 Creating VLAN', ['name' => $vlanName, 'parent' => $parentName, 'vid' => $vid]);
            $vlan = $this->networkManager->createVlan($vlanName, $parentName, $vid);
            $bridge = $this->linkUtils->createBridgeFor($vlan);
            $this->linkUtils->activateLink($bridge);
            $this->allowForwardForLinks->allowForwardForLinks([$bridge->getName()]);
            $this->linkBackup->setPending();
        } catch (Exception $ex) {
            $this->logger->error('LSV3010 Failed to create VLAN', ['exception' => $ex]);
            $this->linkBackup->revert();
            throw $ex;
        }
    }

    /**
     * Deletes the vlan from the device.
     * @param string $vlanLinkId the vlan uuid to be deleted.
     */
    public function deleteVlan(string $vlanLinkId): void
    {
        // Get the connection associated with this link
        $conn = $this->networkManager->getConnection($vlanLinkId);

        // Trace upwards in case this connection is a bridge member
        $top = $this->linkUtils->getTopLevelConnection($conn);

        // Trace downwards to the physical connection
        $physical = $this->linkUtils->getPhysicalConnection($top);

        if (!$physical->isVlan()) {
            throw new Exception("$vlanLinkId is not a vlan", 3);
        }

        // Snapshot the current connection state before changing anything.
        $this->linkBackup->create();

        try {
            $this->logger->info('LSV1020 Deleting VLAN', ['name' => $conn->getDeviceName()]);
            foreach ($this->linkUtils->getHierarchy($top) as $toDelete) {
                $toDelete->delete();
            }
            $this->linkBackup->setPending();
        } catch (Exception $ex) {
            $this->logger->error('LSV3020 Failed to delete VLAN', ['exception' => $ex]);
            $this->linkBackup->revert();
            throw $ex;
        }
    }

    /**
     * Return a network link by its persistent identifier.
     *
     * @param string $id
     * @return NetworkLink|null
     */
    public function getLinkById(string $id): ?NetworkLink
    {
        try {
            $connection = $this->networkManager->getConnection($id);

            // If this connection was invalid or is a slave connection, we can't build a link from it
            if ($connection->isBondMember() || $connection->isBridgeMember()) {
                return null;
            }

            // Start creating the link from the connection's UUID
            $link = new NetworkLink($connection->getUuid());

            // Set the high-level state of this connection
            $link->setState($this->linkUtils->getState($connection));

            // Set the mode based on the ipv4 mode of the underlying connection
            if ($connection->isDisabled()) {
                $mode = NetworkLink::MODE_DISABLED;
            } elseif ($connection->isDhcp()) {
                $mode = NetworkLink::MODE_DHCP;
            } elseif ($connection->isLinkLocal()) {
                $mode = NetworkLink::MODE_LINKLOCAL;
            } else {
                $mode = NetworkLink::MODE_STATIC;
            }

            $allAddrs = $connection->getCurrentAddresses();
            $gateway = $connection->getCurrentGateway();
            $firstAddr = reset($allAddrs) ?: null;

            $link->setIpv4Parameters(
                $mode,
                $firstAddr,
                $gateway,
                $connection->isCurrentDefault()
            );

            // If this connection is a bridge, we should store away the name of the underlying interface, so we can easily
            // attach local virts to it. The rest of the processing relies on the bridge slave, so we also update connection
            // to point to that.
            if ($connection->isBridge()) {
                $link->setBridgeInterface($connection->getDeviceName());
                $connection = $this->linkUtils->getPhysicalConnection($connection);
            }

            // If the connection (or the bridge slave connection) is a bond, determine the names of its members
            if ($connection->isBond()) {
                $bondIface = $connection->getDeviceName();

                // Set the name of the link to be the name of the bond (e.g. bond0)
                $link->setName($bondIface);

                // Get the connections for the bond slaves
                $memberNames = [];
                $bondMembers = $this->networkManager->getSlaveConnectionsForInterface($connection->getDeviceName());
                foreach ($bondMembers as $bondMember) {
                    $memberNames[] = $bondMember->getDeviceName();
                }

                // Configure the bond options for this Link
                $primary = $connection->getBondOptions()['primary'] ?? null;
                $link->setBondParameters($connection->getBondOptions()['mode'] ?? 'unknown', $memberNames, $primary);
            } else {
                // Set the name of the link to be the name of the underlying interface
                // TODO: Come up with some kind of lookup to get a UI name separate from the interface name, which will
                // let us switch away from kernel names and to systemd persistent net naming
                $link->setName($connection->getDeviceName());
            }

            if ($connection->isVlan()) {
                $link->setVlanId($connection->getVlanId());

                // VLAN Link naming is a pain. In the code today, we create the VLAN from the top-level bridge connection,
                // (so the actual connection is named (e.g. br0.10) but we actually want to name it from the underlying
                // physical connection (e.g. eth0.10, bond0.10). This causes us to do some gymnastics to trace through
                // the connection stack to get that information.
                // TODO: Improve this when we implement a better lookup for UI Naming
                $parentConnections = $this->networkManager->getConnectionsForInterface($connection->getVlanDevice());
                if ($parent = reset($parentConnections)) {
                    $parentPhysical = $this->linkUtils->getPhysicalConnection($parent);
                    $link->setName($parentPhysical->getDeviceName() . '.' . $connection->getVlanId());
                }
            }

            // Get the NetworkDevice, which will let us query physical parameters from the device. If we don't
            // have it, we can still pull a little from the configuration.
            if ($device = $connection->getDevice()) {
                $link->setMac($device->getMacAddr());
                $link->setJumboFrames($device->getMtu() > self::MTU_STANDARD);
                $link->setLinkSpeed($device->getSpeed());
                $link->setCarrier($device->getCarrier());
            } else {
                $link->setJumboFrames($connection->getMtu() > self::MTU_STANDARD);
            }

            return $link;
        } catch (Exception $exception) {
            $this->logger->warning('LSV2002 Exception when getting link by ID', [
                'id' => $id,
                'exception' => $exception
            ]);
            return null;
        }
    }

    /**
     * Configures the basic IPv4 properties of a Network Link.
     *
     * @param string $linkId The internal ID of the NetworkLink
     * @param string $mode The IP configuration mode of this link (dhcp, static, link-local, disabled)
     * @param IpAddress|null $address The address and mask to set (when configuring for a static IP)
     * @param IpAddress|null $gateway The default gateway (when configuring for a static IP)
     * @param bool|null $jumboFrames Whether to configure this link for jumbo frames (MTU 9000)
     */
    public function configureLink(
        string $linkId,
        string $mode,
        ?IpAddress $address,
        ?IpAddress $gateway,
        ?bool $jumboFrames
    ): void {
        // In the future, we should back up our connections here before we make any changes. After making a change
        // to any NetworkManager connections, if we don't get a good confirmation from the user (via an API call)
        // that a change was successful, we should roll back the change after ~5 minutes. This will prevent the
        // user from applying bad configuration data that prevents the device from being accessible.
        $context = [
            'linkId' => $linkId,
            'mode' => $mode,
            'address' => $address,
            'gateway' => $gateway,
            'jumbo' => $jumboFrames
        ];
        $this->logger->info('LSV1001 Configuring Network Link', $context);

        // Verify that the connection to be modified actually exists before making any changes
        $connection = $this->networkManager->getConnection($linkId);
        $physical = $this->linkUtils->getPhysicalConnection($connection);

        // Snapshot the current connection state before changing anything
        $this->linkBackup->create();

        // Any errors within this try will cause a revert to the snapshot
        try {
            // Configure the IP/Mask/Gateway based on the given parameters
            if ($mode === NetworkLink::MODE_DISABLED) {
                $connection->setDisabled();
            } elseif ($mode === NetworkLink::MODE_DHCP) {
                $connection->setDhcp();
            } elseif ($mode === NetworkLink::MODE_LINKLOCAL) {
                $connection->setLinkLocal();
            } elseif ($mode === NetworkLink::MODE_STATIC) {
                if (!$address) {
                    throw new RuntimeException('Cannot set static IP of null');
                }
                $connection->setStatic($address, $gateway);
            } else {
                throw new RuntimeException('Invalid Mode: ' . $mode);
            }

            // Configure us for jumbo/auto MTU
            if ($jumboFrames !== null) {
                $physical->setMtu($jumboFrames ? self::MTU_JUMBO : null);
            }

            // Activate the connections we just configured
            $this->linkUtils->activateLink($connection);

            // For DHCP or Link-Local connections, pause for just a short while to allow a lease to potentially
            // be obtained before returning. Otherwise the UI will immediately refresh, and the connection will
            // not be fully populated
            if ($mode === NetworkLink::MODE_LINKLOCAL || $mode === NetworkLink::MODE_DHCP) {
                $this->sleep->sleep(10);
            }

            // Mark the configuration as "Pending Confirmation" from the User/UI
            $this->linkBackup->setPending();
        } catch (Throwable $ex) {
            $context['exception'] = $ex;
            $this->logger->error('LSV3001 Failed to configure network link', $context);
            $this->linkBackup->revert();
            throw $ex;
        }
    }

    /**
     * Creates a bond in the following form.
     *          brbond0 (new bridge for bond, Ip address assigned here.)
     *             |
     *           bond0        br2   br3  ... brN (Existing bridges, Ip address assigned here as well.)
     *          /     \        |     |   ...  |
     *      eth0       eth1   eth2  eth3 ... ethN
     * @param string $bondMode ( balance-rr, active-backup or 802.3ad )
     * @param array $memberLinkIds bridge/ethernet uuids that should be part of the bond.
     * @param ?string $primaryLinkId the interface in 'memberLinkIds' that is the primary in 'active-backup' mode.
     */
    public function createBond(string $bondMode, array $memberLinkIds, ?string $primaryLinkId = null): void
    {
        // Make sure the bond mode is one of our supported modes
        if (!in_array($bondMode, LinkService::SUPPORTED_BOND_MODES)) {
            throw new RuntimeException('Unsupported bond mode: ' . $bondMode);
        }

        if ($bondMode === 'active-backup' && !$primaryLinkId) {
            throw new RuntimeException("No primary interface specified");
        }

        // Make sure we're creating the bond from at least 2 connections.
        if (count($memberLinkIds) < 2) {
            throw new RuntimeException('Need at least 2 interfaces to create a bond.');
        }

        // Make sure the system does not already have a bond interface present.
        $allCons = $this->networkManager->getAllConnections();
        foreach ($allCons as $conn) {
            if ($conn->isBond()) {
                throw new RuntimeException('A bond connection already exists');
            }
        }

        // Call into the helper method to normalize the connection ids into usable objects
        $params = $this->linkUtils->deduceBondParameters($memberLinkIds, $primaryLinkId);

        // Set up the bond options
        $bondOptions['mode'] = $bondMode;
        if ($bondMode === 'active-backup' && $params['primary']) {
            $bondOptions['primary'] = $params['primary'];
        }

        // Determine if we need to clone one of the members' MAC addresses
        $mac = $this->linkUtils->getBondClonedMac($params['devices']);

        // Snapshot the current connection state before changing anything
        $this->linkBackup->create();
        try {
            $this->logger->info('LSV1030 Creating Network Bond', [
                'name' => self::BOND_NAME,
                'delete' => array_map(fn($conn) => $conn->getDeviceName(), $params['delete']),
                'devices' => $params['devices'],
                'options' => $bondOptions,
                'mac' => $mac
            ]);

            // Delete all the prior connections
            foreach ($params['delete'] as $conn) {
                $conn->delete();
            }

            // Create the bond connection, and set the options on it
            $bond = $this->networkManager->createBond(self::BOND_NAME);
            $bond->setBondOptions($bondOptions);
            if ($mac) {
                $bond->setClonedMac($mac);
            }

            // Create the ethernet connections that will be added to the bond
            foreach ($params['devices'] as $device) {
                $eth = $this->networkManager->createEthernet($device);
                $eth->addToBond(self::BOND_NAME);
            }

            // Create the bridge connection on top of the bond
            $brbond = $this->linkUtils->createBridgeFor($bond);

            // Enable the new bond
            $this->linkUtils->activateLink($brbond);

            $this->allowForwardForLinks->allowForwardForLinks([$brbond->getName()]);
            // Mark the configuration as "Pending Confirmation" from the User/UI
            $this->linkBackup->setPending();
        } catch (Throwable $ex) {
            $this->logger->error('LSV3030 Failed to create bond', ['exception' => $ex]);
            $this->linkBackup->revert();
            throw $ex;
        }
    }

    /**
     * Removes network bond and re-creates the non-bonded interface configuration as follows.
     *    br0   br1   br2  ...  brN   (Ip address assigned to bridges)
     *     |     |     |   ...  |
     *    eth0  eth1  eth2 ... ethN
     */
    public function removeBond(): void
    {
        $allCons = $this->networkManager->getAllConnections();
        $bondConns = array_values(array_filter($allCons, fn($conn) => $conn->isBond()));
        $bondCount = count($bondConns);

        // Make sure we have exactly one bond, regardless of its name.
        if ($bondCount !== 1) {
            throw new RuntimeException("Invalid bond count: $bondCount");
        }

        // Get the entire hierarchy of the bond
        $bond = reset($bondConns);
        $params = $this->linkUtils->deduceBondParameters([$bond->getUuid()]);

        // Snapshot the current connection state before changing anything
        $this->linkBackup->create();

        try {
            $this->logger->info('LSV1040 Removing network bond', [
                'name' => $bond->getDeviceName(),
                'delete' => array_map(fn($conn) => $conn->getDeviceName(), $params['delete']),
                'devices' => $params['devices']
            ]);

            // Delete the old connections, destroying everything associated with the bond
            foreach ($params['delete'] as $conn) {
                $conn->delete();
            }

            $addedBridgeNames = [];
            // Create new Ethernet/Bridge combos for each freed device, and activate each of them
            foreach ($params['devices'] as $device) {
                $eth = $this->networkManager->createEthernet($device);
                $bridge = $this->linkUtils->createBridgeFor($eth);
                $addedBridgeNames []= $bridge->getName();
                $this->linkUtils->activateLink($bridge);
            }

            $this->allowForwardForLinks->allowForwardForLinks($addedBridgeNames);
            // Mark the configuration as "Pending Confirmation" from the User/UI
            $this->linkBackup->setPending();
        } catch (Throwable $ex) {
            $this->logger->error('LSV3040 Failed to remove bond', ['exception' => $ex]);
            $this->linkBackup->revert();
            throw $ex;
        }
    }

    /**
     * Does a full stop/reload/start cycle to reload all the connections and apply them from the ground up.
     */
    public function restartNetworking(): void
    {
        $this->networkManager->restartNetworking();
    }
}
