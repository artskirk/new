<?php

namespace Datto\Utility\Network\NetworkManager;

use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Filesystem;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Network\CachingNmcli;
use Exception;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;

/**
 * Primary API for working with NetworkManager. This provides a number of convenience methods for interacting with
 * the top-level manager, as well as getting NetworkDevice and NetworkConnection objects.
 *
 * This class (and the NetworkDevice and NetworkConnection) classes primarily interacts with NetworkManager through
 * the `nmcli` tool, though it should be fairly straightforward to convert it to using the DBus API, since all of
 * the same information is available there. This would improve our ability to interact with NetworkManager as a non-
 * root user, as well as potentially improving performance, and dramatically reducing the amount of text parsing
 * necessary.
 *
 * @see https://networkmanager.dev/docs/
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class NetworkManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const CONNECTIONS_DIR = '/etc/NetworkManager/system-connections/';
    private const RUNTIME_CONNECTIONS_DIR = '/run/NetworkManager/system-connections/';
    private const DISTRO_CONNECTIONS_DIR = '/lib/NetworkManager/system-connections/';

    private Filesystem $filesystem;
    private CachingNmcli $nmcli;
    private NetworkConnectionFactory $connectionFactory;
    private Sleep $sleep;

    public function __construct(
        Filesystem $filesystem,
        CachingNmcli $nmcli,
        NetworkConnectionFactory $connectionFactory,
        Sleep $sleep
    ) {
        $this->filesystem = $filesystem;
        $this->nmcli = $nmcli;
        $this->connectionFactory = $connectionFactory;
        $this->sleep = $sleep;
    }

    /**
     * Disables all networking entirely. This will bring down and delete all network interfaces.
     */
    public function disableNetworking()
    {
        $this->nmcli->networkingOff();
    }

    /**
     * Enables networking. You should generally reload connections right before doing this, so that the network
     * comes up with the most up-to-date configuration.
     */
    public function enableNetworking()
    {
        $this->nmcli->networkingOn();
    }

    /**
     * Tells NetworkManager to reload its connections from disk. Similar to a systemctl daemon-reload, this only makes
     * NetworkManager aware of changes, but does not cause the changes to be applied.
     */
    public function reloadConnections()
    {
        $this->nmcli->connectionReload();
    }

    /**
     * Does a full stop/reload/start cycle to reload all the connections and apply them from the ground up.
     */
    public function restartNetworking()
    {
        $this->disableNetworking();

        // Sleep for a few seconds before and after reloading connections. There appears to be a race condition here:
        // https://gitlab.freedesktop.org/NetworkManager/NetworkManager/-/issues/911
        $this->sleep->sleep(1);
        $this->reloadConnections();
        $this->sleep->sleep(1);

        $this->enableNetworking();
    }

    /**
     * Performs a NetworkManager connectivity check, returning the current connectivity state. According to the
     * documentation, the possible states are as follows:
     *   none: the host is not connected to any network.
     *   portal: the host is behind a captive portal and cannot reach the full Internet.
     *   limited: the host is connected to a network, but it has no access to the Internet.
     *   full: the host is connected to a network and has full access to the Internet.
     *   unknown: the connectivity status cannot be found out.
     *
     * @param bool $check true to force a connectivity re-check, or false to use NetworkManager's cached connectivity
     * @return string The connectivity state (none, portal, limited, full, unknown)
     */
    public function connectivity(bool $check = false): string
    {
        return $this->nmcli->networkingConnectivity($check);
    }

    /**
     * Exports NetworkManager persistent connections by copying them from the system-connections directory to
     * the given location. The original connections are not deleted or modified in any way.
     *
     * @param string $destination The destination directory. Will be created if it does not exist.
     */
    public function exportConnections(string $destination)
    {
        if (!$this->filesystem->isDir(self::CONNECTIONS_DIR)) {
            // Early return. If the directory doesn't exist, it means we don't have any saved connections to export
            return;
        }

        $this->filesystem->mkdirIfNotExists($destination, true);

        if (!$this->filesystem->isDir($destination)) {
            $this->logger->error('NMG0001 Could not export connections', ['destination' => $destination]);
            throw new RuntimeException('Could not export connections. Destination is not a directory.');
        }

        foreach ($this->filesystem->scanDir(self::CONNECTIONS_DIR) as $fileName) {
            if (strpos($fileName, '.nmconnection') !== false) {
                if ($this->filesystem->copy(self::CONNECTIONS_DIR . $fileName, $destination) === false) {
                    throw new RuntimeException('Could not export connection: ' . $fileName);
                }
            }
        }
    }

    /**
     * Imports connections from a given directory by copying them to the system-connections directory. It is recommended
     * to delete any existing connections before importing new ones with the `deleteAllConnections()` call. After
     * importing, connections must be reloaded so NetworkManager will re-scan the files and pick up changes.
     *
     * @param string $source The source directory containing the connection files to import
     */
    public function importConnections(string $source)
    {
        if (!$this->filesystem->isDir($source)) {
            $this->logger->error('NMG0010 Could not import connections', ['source' => $source]);
            throw new RuntimeException('Could not import connections. Source is not a directory.');
        }

        foreach ($this->filesystem->scanDir($source) as $fileName) {
            if (strpos($fileName, '.nmconnection') !== false) {
                $this->filesystem->copy("$source/$fileName", self::CONNECTIONS_DIR);
                $this->filesystem->chown(self::CONNECTIONS_DIR . $fileName, 'root');
                $this->filesystem->chmod(self::CONNECTIONS_DIR . $fileName, 0600);
            }
        }
    }

    /**
     * Deletes ALL the connections managed by NetworkManager, including Runtime and Distro-installed, even though we
     * don't currently expect those on the system. If running this while NetworkManager is running, make sure to
     * call `reloadConnections()` after so that NetworkManager picks up the changes.
     */
    public function deleteAllConnections(): void
    {
        $dirs = [self::CONNECTIONS_DIR, self::RUNTIME_CONNECTIONS_DIR, self::DISTRO_CONNECTIONS_DIR];
        foreach ($dirs as $dir) {
            if ($this->filesystem->isDir($dir)) {
                $scanned = $this->filesystem->scanDir($dir);
                $currentConnections = array_diff($scanned, ['..', '.']);
                foreach ($currentConnections as $connectionFile) {
                    $this->filesystem->unlink($dir . $connectionFile);
                }
            }
        }
    }

    /**
     * Gets all the NetworkConnections currently managed by NetworkManager
     *
     * @return NetworkConnection[] An array of all the managed connections
     */
    public function getAllConnections(): array
    {
        $uuids = $this->nmcli->getConnectionUuids();

        return array_values(array_filter(array_map(fn ($uuid) => $this->connectionFactory->getConnection($uuid), $uuids)));
    }

    /**
     * Gets all the top-level (un-enslaved) connections. For example, in a topology as follows:
     *
     * br0.20                  brbond0
     *   \                       |
     *     br0     br1         bond0
     *      |       |         /    \
     *    eth0    eth1    eth2     eth3
     *
     * This would return br0.20, br0, br1, and brbond0.
     *
     * @return NetworkConnection[] Array of all the non-slave connections
     */
    public function getTopLevelConnections(): array
    {
        $connections = $this->getAllConnections();

        return array_values(array_filter($connections, static fn ($con) => empty($con->getMaster())));
    }

    /**
     * Get all the connections that are defined for a given network interface.
     *
     * @param string $iface The network interface to look for associated connections for
     * @return NetworkConnection[] The array of connections defined for the interface, if any
     */
    public function getConnectionsForInterface(string $iface): array
    {
        $networkConnections = $this->getAllConnections();

        return array_values(array_filter($networkConnections, static fn ($nc) => $nc->getDeviceName() === $iface));
    }

    /**
     * Gets all the connection that are configured as slaves of a given master interface.
     *
     * @param string $iface The interface name of the master connection to search for slaves of
     * @return NetworkConnection[]
     */
    public function getSlaveConnectionsForInterface(string $iface): array
    {
        $networkConnections = $this->getAllConnections();

        return array_values(array_filter($networkConnections, static fn ($nc) => $nc->getMaster() === $iface));
    }

    /**
     * Gets a connection from the ConnectionFactory
     *
     * @param string $identifier
     * @return NetworkConnection
     */
    public function getConnection(string $identifier): NetworkConnection
    {
        $connection = $this->connectionFactory->getConnection($identifier);
        if (!$connection) {
            throw new RuntimeException("Connection '$identifier' does not exist");
        }
        return $connection;
    }

    /**
     * Gets all the NetworkDevices that are managed by NetworkManager
     *
     * @param bool $ethernetOnly Only return ethernet (non-bridge/bond/vlan/etc...) devices
     *
     * @return NetworkDevice[]
     */
    public function getManagedDevices(bool $ethernetOnly = false): array
    {
        $devices = array_filter($this->nmcli->deviceShowDetails(), static fn ($dev) => $dev['GENERAL.NM-MANAGED'] === 'yes');

        if ($ethernetOnly) {
            $devices = array_filter($devices, static fn ($dev) => $dev['GENERAL.TYPE'] === 'ethernet');
        }

        return array_values(array_filter(array_map(fn ($dev) => $this->connectionFactory->getDevice($dev['GENERAL.DEVICE']), $devices)));
    }

    /**
     * Gets a NetworkDevice from the ConnectionFactory
     *
     * @param string $iface
     * @return NetworkDevice|null
     */
    public function getDevice(string $iface): ?NetworkDevice
    {
        return $this->connectionFactory->getDevice($iface);
    }

    /**
     * Create a connection to manage an Ethernet interface. New connections are not automatically
     * enabled or connected.
     *
     * @param string $iface The name of the underlying ethernet interface
     * @param string|null $name The name of the connection to be created. null to use a default name.
     * @return NetworkConnection The connection object
     */
    public function createEthernet(string $iface, ?string $name = null): NetworkConnection
    {
        return $this->createConnection('802-3-ethernet', $iface, $name ?? $iface);
    }

    /**
     * Create a connection to manage a bridge interface. New connections are not automatically
     * enabled or connected.
     *
     * @param string $iface The name of the underlying bridge interface
     * @param string|null $name The name of the connection to be created. null to use a default name.
     * @return NetworkConnection The connection object
     */
    public function createBridge(string $iface, ?string $name = null): NetworkConnection
    {
        return $this->createConnection('bridge', $iface, $name ?? $iface);
    }

    /**
     * Create a connection to manage a bridge interface. New connections are not automatically
     * enabled or connected.
     *
     * @param string $iface The name of the underlying bond interface
     * @param string|null $name The name of the connection to be created. null to use a default name.
     * @return NetworkConnection The connection object
     */
    public function createBond(string $iface, ?string $name = null): NetworkConnection
    {
        return $this->createConnection('bond', $iface, $name ?? $iface);
    }

    /**
     * Create a connection to manage a VLAN interface attached to a network device. New connections
     * are not automatically enabled or connected.
     *
     * @param string $iface The name of the underlying vlan interface
     * @param string|null $name The name of the connection to be created. null to use a default name.
     * @return NetworkConnection The connection object
     */
    public function createVlan(string $iface, string $device, int $vlanId, ?string $name = null): NetworkConnection
    {
        return $this->createConnection('vlan', $iface, $name ?? $iface, ['dev', $device, 'id', $vlanId]);
    }

    /**
     * Factory method to actually create a new system NetworkConnection, and return the object
     * that allows management of that connection.
     *
     * @param string $type The type of connection
     * @param string $iface The name of the interface that will be managed by this connection
     * @param string $name The name of the connection
     * @param string[] $extra Any extra parameters needed at creation time
     *
     * @return NetworkConnection The newly-created connection
     */
    private function createConnection(string $type, string $iface, string $name, array $extra = []): NetworkConnection
    {
        // By default, NetworkManager creates all connections with `autoconnect: yes`. This results in connections
        // being activated as soon as they are created, but before they are actually configured. Since our create/
        // configure steps are separate, disable autoconnect initially, so that the configure step can enable it later.
        $extra = array_merge($extra, ['autoconnect', 'no']);

        $result = $this->nmcli->connectionAdd($type, $iface, $name, $extra);

        // NetworkManager is helpful enough to warn us when we're doing something stupid. Make sure we propagate
        // the warning message to the logs when this is the case.
        if ($result['error']) {
            $this->logger->warning('NMG0002 NetworkManager warning while adding Connection', [
                'name' => $name,
                'iface' => $iface,
                'message' => $result['error']
            ]);
        }

        if (preg_match('/Connection \'(?<name>.*)\'\s+\((?<uuid>.*)\)/s', $result['output'], $matches)) {
            $this->logger->info('NMG0003 Created Network Connection', [
                'name' => $matches['name'],
                'uuid' => $matches['uuid'],
                'iface' => $iface,
                'type' => $type,
            ]);

            $connection = $this->connectionFactory->getConnection($matches['uuid']);
            if (!$connection) {
                throw new RuntimeException('Could not retrieve connection: ' . $matches['uuid']);
            }
            return $connection;
        } else {
            throw new Exception('Could not create network connection');
        }
    }
}
