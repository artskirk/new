<?php

namespace Datto\Virtualization;

use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\EsxConnectionService;
use Exception;
use Vmwarephp\Vhost;

/**
 * A factory to create a Vhost Object
 *
 * Creates a singleton Vhost instance.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class VhostFactory
{
    /** @var EsxConnectionService Services for managing ESX connections */
    private $esxConnectionService;

    // static cache for singleton pattern.

    /** @var Vhost[] */
    private static $cachedVhosts = [];

    /**
     * @param EsxConnectionService|null $esxConnectionService Services for managing ESX connections
     */
    public function __construct(EsxConnectionService $esxConnectionService = null)
    {
        $this->esxConnectionService = $esxConnectionService ?: new EsxConnectionService();
    }

    /**
     * Create a vhost
     *
     * @param string $connectionName VHost connection name
     * @return Vhost Object for interacting with a VHost connection
     */
    public function create($connectionName): Vhost
    {
        if (!isset(static::$cachedVhosts[$connectionName])) {
            $esxConnection = $this->esxConnectionService->get($connectionName);

            if (!$esxConnection) {
                throw new Exception('Please specify one valid connection name.');
            }

            static::$cachedVhosts[$connectionName] = new Vhost(
                $esxConnection->getPrimaryHost(),
                $esxConnection->getUser(),
                $esxConnection->getPassword()
            );
        }

        return static::$cachedVhosts[$connectionName];
    }
}
