<?php

namespace Datto\Service\Networking;

use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Network\NetworkManager\NetworkConnection;
use Datto\Utility\Network\NetworkManager\NetworkDevice;
use Datto\Utility\Network\NetworkManager\NetworkManager;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Contains methods to scan the device for problems, and optionally perform automatic repairs.
 *
 * If this class grows, it may make sense to expand it into more of a framework like the config repair service,
 * where each "check" would be its own class, and optionally support repairing. For now, with only a few simple
 * checks and repairs, it's kept simple.
 */
class LinkProblemService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private NetworkManager $networkManager;
    private LinkBackup $linkBackup;
    private LinkUtils $linkUtils;

    public function __construct(
        NetworkManager $networkManager,
        LinkBackup $linkBackup,
        LinkUtils $linkUtils
    ) {
        $this->networkManager = $networkManager;
        $this->linkBackup = $linkBackup;
        $this->linkUtils = $linkUtils;
    }

    /**
     * Scan the device network for problems, and optionally repair any of those that support it.
     *
     * @param bool $repair Whether to perform automatic repairs
     */
    public function scan(bool $repair = false): void
    {
        // Make sure to reload connections so NetworkManager has the most up-to-date connection information
        // before running the scan. Since the automatic scan runs very early after NetworkManager.service starts,
        // this forces us to wait for NetworkManager to fully read the connection files from disk before proceeding
        // with automatic fixups.
        $this->networkManager->reloadConnections();

        $this->checkNoConnectionForManagedEth($repair);
        $this->checkVlanParents($repair);
        $this->checkNoBridgeForInterface($repair);
        $this->checkMultipleConnectionsForDevice($repair);
    }

    /**
     * Checks for the existance of a "Managed" ethernet device with no configured NetworkManager connection
     * profiles.
     *
     * Optionally, this configuration can be repaired by automatically creating default eth+bridge connections set
     * to automatically start at boot, and configured for DHCP.
     *
     * @param bool $repair
     * @return void
     */
    private function checkNoConnectionForManagedEth(bool $repair)
    {
        /** @var NetworkDevice[] $fixDevices */
        $fixDevices = [];

        $managedDevices = $this->networkManager->getManagedDevices(true);
        foreach ($managedDevices as $device) {
            if (empty($device->getAvailableConnectionUuids())) {
                $fixDevices[] = $device;
                $this->logger->warning('LPS3001 A managed device has no available connections', [
                    'device' => $device->getName()
                ]);
            }
        }

        if ($repair) {
            foreach ($fixDevices as $device) {
                $this->logger->info('LPS1001 Creating default connection profile for managed device', [
                    'device' => $device->getName()
                ]);
                $eth = $this->networkManager->createEthernet($device->getName());
                $bridge = $this->linkUtils->createBridgeFor($eth);
                $this->linkUtils->activateLink($bridge);
            }
        }
    }

    /**
     * Checks for any Ethernet, bond, or vlan interfaces that are not members of a bridge.
     *
     * Optionally, this will create a bridge over them, and transfer the IP settings of the ethernet or bond
     * to the bridge.
     *
     * @param bool $repair
     * @return void
     */
    private function checkNoBridgeForInterface(bool $repair)
    {
        $topLevel = $this->networkManager->getTopLevelConnections();
        /** @var NetworkConnection[] $nonBridge */
        $nonBridge = array_values(array_filter($topLevel, fn($conn) => $conn->isBond() || $conn->isEthernet() || $conn->isVlan()));

        foreach ($nonBridge as $conn) {
            $this->logger->warning('LPS3002 Device is not a bridge member', [
                'device' => $conn->getDeviceName(),
                'type' => $conn->getType()
            ]);
        }

        if ($repair) {
            foreach ($nonBridge as $conn) {
                $device = $conn->getDeviceName();
                $enabled = $conn->isAutoconnect();
                $config = $conn->exportIpConfig();
                $this->logger->info('LPS1002 Creating bridge for device', [
                    'device' => $device,
                    'enabled' => $enabled,
                    'config' => $config
                ]);

                $this->linkBackup->create();
                try {
                    // Create the bridge and import the configuration from the previous connection
                    $bridge = $this->linkUtils->createBridgeFor($conn);
                    $bridge->importIpConfig($config);

                    // Activate the hierarchy and commit the changes if everything succeeded
                    $this->linkUtils->activateLink($bridge);
                    $this->linkBackup->setPending();
                    $this->linkBackup->commit();
                } catch (Throwable $exception) {
                    $this->logger->error('LPS4002 Could not create bridge for device', [
                        'device' => $device,
                        'exception' => $exception
                    ]);
                    $this->linkBackup->revert();
                }
            }
        }
    }

    /**
     * It's pretty bad for us to have multiple configured connections for a single device. I'm not totally sure
     * what we could do that's automatic without risking deleting valid connections, but at least we can throw
     * a warning here and keep an eye on how frequently this happens across the fleet
     *
     * @param bool $repair Not currently implemented, left in for API consistency
     * @return void
     */
    public function checkMultipleConnectionsForDevice(bool $repair)
    {
        $managedDevices = $this->networkManager->getManagedDevices(true);
        foreach ($managedDevices as $device) {
            $uuids = $device->getAvailableConnectionUuids();
            if (count($uuids) > 1) {
                $this->logger->warning('LPS3003 Multiple available connections for device', [
                    'device' => $device->getName(),
                    'uuids' => $uuids
                ]);
            }
        }
    }

    /**
     * During testing, it was found that having a VLAN device with a bridge configured as a vlan parent will
     * result in the VLAN carrier going down when the bridge is modified.
     *
     * For example, the configuration on the left will break when br0 is modified, while the configuration
     * on the right will continue working. In all other regards, the two configurations appear to be functionally
     * equivalent.
     *
     *           br0.20                 br0  eth0.20
     *         /                         |   /
     *      br0                         eth0
     *       |
     *     eth0
     *
     * This scan and repair function will detect this configuration, and optionally fix it, by reconfiguring
     * the VLAN parent to set its parent to the physical device under the bridge.
     *
     * @return void
     */
    private function checkVlanParents(bool $repair)
    {
        /** @var NetworkConnection[] $needRepair */
        $needRepair = [];

        /** @var NetworkConnection[] $vlans */
        $vlans = array_values(array_filter($this->networkManager->getAllConnections(), fn ($conn) => $conn->isVlan()));
        foreach ($vlans as $vlan) {
            try {
                $parent = $this->linkUtils->getVlanParent($vlan);
                if ($parent->isBridge()) {
                    $this->logger->warning('LPS4003 VLAN connection with Bridge Parent', [
                        'device' => $vlan->getDeviceName(),
                        'parent' => $parent->getDeviceName()
                    ]);
                    $needRepair[] = $vlan;
                }
            } catch (Throwable $exception) {
                $this->logger->error('LPS4004 Could not get VLAN Parent Connection', [
                    'device' => $vlan->getDeviceName(),
                    'exception' => $exception
                ]);
            }
        }

        if ($repair) {
            foreach ($needRepair as $vlan) {
                try {
                    $oldParent = $this->linkUtils->getVlanParent($vlan);
                    $newParent = $this->linkUtils->getPhysicalConnection($oldParent);

                    // Gets the new name for a VLAN device. This isn't strictly required, since just setting the
                    // vlan.parent is enough, but having a device name `br0.25` with a vlan parent of `eth0` is
                    // potentially confusing, so we'll change the device name here for the sake of consistency
                    $newName = str_replace($oldParent->getDeviceName(), $newParent->getDeviceName(), $vlan->getDeviceName());

                    $this->logger->info('LPS2004 Retargeting VLAN to physical device', [
                        'oldVlan' => $vlan->getDeviceName(),
                        'oldParent' => $oldParent->getDeviceName(),
                        'newParent' => $newParent->getDeviceName(),
                        'newVlan' => $newName
                    ]);

                    // Create a backup in case anything goes wrong
                    $this->linkBackup->create();

                    // Stop the connection, since we're changing fundamental properties of it
                    $vlan->deactivate();

                    // Set the new parent device, and re-name the interface and connection accordingly
                    $vlan->setVlanParent($newParent->getDeviceName());
                    $vlan->setDeviceName($newName);
                    $vlan->setName($newName);

                    // Bring the VLAN back up
                    $vlan->activate();

                    // Commit the changes
                    $this->linkBackup->setPending();
                    $this->linkBackup->commit();
                } catch (Throwable $exception) {
                    $this->logger->error('LPS4014 Could not retarget VLAN parent', [
                        'device' => $vlan->getDeviceName(),
                        'exception' => $exception
                    ]);
                    $this->linkBackup->revert();
                }
            }
        }
    }
}
