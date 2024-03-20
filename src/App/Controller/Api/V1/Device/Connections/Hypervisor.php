<?php

namespace Datto\App\Controller\Api\V1\Device\Connections;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractAuthConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Psr\Log\LoggerAwareInterface;
use Datto\Log\LoggerAwareTrait;
use Datto\Log\SanitizedException;
use Datto\Restore\RestoreService;
use Exception;
use Throwable;

/**
 * This class contains the API endpoints for managing Hyper-V and Esx hypervisor connections.
 */
class Hypervisor implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var ConnectionService */
    protected $connectionService;

    /** @var EsxConnectionService */
    protected $esxConnectionService;

    /** @var HvConnectionService */
    protected $hvConnectionService;

    /** @var RestoreService */
    protected $restoreService;

    public function __construct(
        ConnectionService $connectionService,
        EsxConnectionService $esxConnectionService,
        HvConnectionService $hvConnectionService,
        RestoreService $restoreService
    ) {
        $this->connectionService = $connectionService;
        $this->esxConnectionService = $esxConnectionService;
        $this->hvConnectionService = $hvConnectionService;
        $this->restoreService = $restoreService;
    }

    /**
     * Attempts to connect to an existing connection. Throws an exception if connect fails
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_RESTORE_VIRTUALIZATION_READ")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "connectionName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $connectionName
     */
    public function checkConnection($connectionName): void
    {
        $connection = $this->connectionService->get($connectionName);

        /** @var AbstractAuthConnection $connection */
        $user = $connection->getUser();
        $pass = $connection->getPassword();
        $params = [
            'username' => $user,
            'password' => $pass
        ];

        try {
            if ($connection->isHyperV()) {
                /** @var HvConnection $connection */
                $params['server'] = $connection->getHostname();
                $params['domain'] = $connection->getDomain();
                $params['http'] = $connection->isHttp();
                $this->hvConnectionService->connect($params);
            } else {
                /** @var EsxConnection $connection */
                $params['server'] = $connection->getPrimaryHost();
                $this->esxConnectionService->connect($params);
            }
        } catch (Throwable $e) {
            $sanitizedException = new SanitizedException($e, [$user, $pass]);
            if ($connection->isHyperV() && HvConnectionService::isInvalidCertificateError($e)) {
                $this->logger->error('HYP1002 Hyper-V certificate error', ['exception' => $sanitizedException]);
            }
            throw $sanitizedException;
        }
    }

    /**
     * Checks whether provided connection info is valid.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "server" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:graph:]]+$~"),
     *   "username" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:graph:]]+$~"),
     *   "password" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:graph:]]+$~"),
     *   "type" = @Symfony\Component\Validator\Constraints\Choice(choices = {"esx", "hyperv"}),
     *   "domain" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[[:graph:]]+$~"),
     *   "http" = @Symfony\Component\Validator\Constraints\Choice(choices = {true, false})
     * })
     * @param string $server
     * @param string $username
     * @param string $password
     * @param string $type
     * @param string $domain
     * @param bool $http
     */
    public function connect($server, $username, $password, $type, $domain = "", $http = true): void
    {
        $params = [
            'server' => $server,
            'username' => $username,
            'password' => $password
        ];

        try {
            if ($type === ConnectionType::LIBVIRT_HV) {
                $params['domain'] = $domain;
                $params['http'] = $http;
                $this->hvConnectionService->connect($params);
            } else {
                $this->esxConnectionService->connect($params);
            }
        } catch (Throwable $e) {
            throw new SanitizedException($e, [$username, $password]);
        }
    }

    /**
     * Can be used to "dry-run" connection creation (as last step's validation) or to create the connection.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_CREATE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "type" = @Symfony\Component\Validator\Constraints\Choice(choices = {"esx", "hyperv"})
     * })
     * @param string $type
     * @param string $name
     * @param array $params
     * @return bool true if saved successfully
     */
    public function create($type, $name, $params)
    {
        $connectionService = ($type === ConnectionType::LIBVIRT_HV)
            ? $this->hvConnectionService : $this->esxConnectionService;

        $existing = $connectionService->get($name);

        if ($existing) {
            throw new Exception("Cannot create connection named \"$name\" as it already exists.");
        }

        $connection = $connectionService->create($name);
        $connection = $connectionService->setConnectionParams($connection, $params);

        $connectionService->save($connection);
        return true;
    }

    /**
     * Can be used to edit a connection
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "type" = @Symfony\Component\Validator\Constraints\Choice(choices = {"esx", "hyperv"}),
     *     "oldName" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $type
     * @param string $oldName
     * @param string $name
     * @param array $params
     * @return bool true if saved successfully
     */
    public function edit($type, $oldName, $name, $params) : bool
    {
        //type is required because setConnectionParams is different for hv/esx
        $connectionService = ($type === ConnectionType::LIBVIRT_HV) ?
            $this->hvConnectionService : $this->esxConnectionService;

        $connection = $connectionService->get($oldName);

        if (!empty($this->restoreService->getActiveRestoresForConnection(($oldName)))) {
            throw new Exception("Active restores found for connection", 857);
        }

        // preserve the flag indicating whether or not this is the primary connection used for screenshots
        $isPrimary = $connection->isPrimary();

        // ensure we can connect with given parameters before deleting old connection
        $connectionService->connect($params);
        $connectionService->delete($connection);

        $connection = $connectionService->create($name);
        $connection = $connectionService->setConnectionParams($connection, $params);

        // reset flag indicating primary connection
        $connection->setIsPrimary($isPrimary);

        $connectionService->save($connection);
        if ($oldName !== $name) {
            $this->logger->debug("HYP1000 Changing hypervisor connection " .
                "name from '$oldName' to '$name'");
            $this->connectionService->updateAgentlessConnectionName($oldName, $name);
        }

        return true;
    }

    /**
     * Deletes the specified connection
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_HYPERVISOR_CONNECTIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_HYPERVISOR_CONNECTION_DELETE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "name" = @Datto\App\Security\Constraints\ConnectionExists()
     * })
     * @param string $name connection name
     * @return bool
     */
    public function delete($name)
    {
        $connection = $this->connectionService->get($name);

        if (!$connection) {
            throw new Exception("Cannot delete connection named \"$name\" as it does not exist.");
        }

        if (!empty($this->restoreService->getActiveRestoresForConnection($name))) {
            throw new Exception("Active restores found for connection", 857);
        }

        return $this->connectionService->delete($connection);
    }
}
