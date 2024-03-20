<?php
/**
 * ConnectionFactory.php
 * @author Nate Levesque <nlevesque@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @copyright 2015 Datto Inc
 */

namespace Datto\Connection;

use Datto\Connection\Service\ConnectionService;

/**
 * @deprecated Use Datto\Connection\Service\* or check the implementation
 */
class ConnectionFactory
{
    /**
     * @var ConnectionService
     */
    private static $connectionService = null;

    /**
     * Instantiates a hypervisor connection provider based on passed arguments.
     *
     * @param string $name
     *  (Optional) A pre-configured connection name.
     * @param ConnectionType $type
     *  (Optional) A connection type.
     *
     * @return ConnectionInterface
     */
    public static function create($name = null, ConnectionType $type = null)
    {
        // If there's no connection service, we will inject a new one
        ConnectionFactory::injectConnectionService(ConnectionFactory::$connectionService);
        return ConnectionFactory::$connectionService->find($name, $type);
    }

    /**
     * This is to make this legacy class unit testable.
     *
     * @param ConnectionService $injectedConnectionService
     */
    public static function injectConnectionService(ConnectionService $injectedConnectionService = null)
    {
        ConnectionFactory::$connectionService = $injectedConnectionService ?: new ConnectionService();
    }
}
