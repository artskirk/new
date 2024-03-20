<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Connection\Libvirt\AbstractAuthConnection;
use Datto\Connection\Service\ConnectionService;
use Exception;

/**
 * This class contains the API endpoints for managing hypervisor connections.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class Connections
{
    /** @var ConnectionService */
    protected $connectionService;

    public function __construct(ConnectionService $connectionService)
    {
        $this->connectionService = $connectionService;
    }

    /**
     * Returns all connection names currently in use.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @return array
     */
    public function getAllNames(): array
    {
        $names = [];
        $connections = $this->connectionService->getAll();

        foreach ($connections as $connection) {
            $names[] = $connection->getName();
        }

        return $names;
    }

    /**
     * Sets the connection as primary connection (e.g. used for screenshotting). Since there can be only one primary,
     * previous primary connection will be altered as well (e.g. flag will be dropped).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $name
     * @return bool
     */
    public function setAsPrimary($name)
    {
        $connection = $this->connectionService->get($name);

        if ($connection === null) {
            throw new Exception("Connection $name does not exist.");
        }

        $this->connectionService->setAsPrimary($connection);

        return true;
    }

    /**
     * Get data about all the device's remote hypervisors, as an array
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_READ")
     * @return array array of arrays containing hypervisor information, with keys "name", "type", "connectionParams"
     */
    public function getAll(): array
    {
        $connections = $this->connectionService->getAll();
        $connectionData = [];

        foreach ($connections as $connection) {
            /** @var AbstractAuthConnection $connection */
            $connectionData[] = $connection->toArray();
        }

        return $connectionData;
    }
}
