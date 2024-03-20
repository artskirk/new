<?php

namespace Datto\Utility\Network\NetworkManager;

use Datto\Common\Utility\Filesystem;
use Datto\Utility\Network\CachingNmcli;
use Throwable;

/**
 * Responsible for Creating NetworkConnection Objects and validating that exactly one was created.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class NetworkConnectionFactory
{
    private CachingNmcli $nmcli;
    private Filesystem $filesystem;

    public function __construct(CachingNmcli $nmcli, Filesystem $filesystem)
    {
        $this->nmcli = $nmcli;
        $this->filesystem = $filesystem;
    }

    /**
     * Get a connection from a connection identifier. This could be a name, uuid, file path, or active path.
     *
     * @param string $identifier The connection identifier
     *
     * @return NetworkConnection|null The connection, or null if no connection object could be created
     */
    public function getConnection(string $identifier): ?NetworkConnection
    {
        $connection = new NetworkConnection($identifier, $this->nmcli, $this);

        try {
            // Attempt to get the connection UUID. This will fail with a Process exception if no connections with
            // the given identifier could be found.
            $uuid = trim($connection->getUuid());
        } catch (Throwable $throwable) {
            return null;
        }

        // Make sure that the trimmed UUID doesn't contain a newline. If it does, it means this identifier is
        // ambiguous, and refers to more than one connection, so it's printing multiple UUIDs. This can happen
        // if two connections are created with the same name, and we try to retrieve them by name. If this is the
        // case, we also want to return null, to prevent undefined behavior.
        if (strpos($uuid, PHP_EOL) !== false) {
            return null;
        }

        return $connection;
    }

    /**
     * Get a device from a linux interface name.
     *
     * @param string $interface The device interface name
     *
     * @return NetworkDevice|null The connection, or null if no connection object could be created
     */
    public function getDevice(string $interface): ?NetworkDevice
    {
        $device = new NetworkDevice($interface, $this->nmcli, $this, $this->filesystem);

        try {
            // Make sure the device actually exists
            $device->getName();
        } catch (Throwable $throwable) {
            return null;
        }

        return $device;
    }
}
