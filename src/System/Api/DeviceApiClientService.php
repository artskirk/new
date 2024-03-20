<?php

namespace Datto\System\Api;

use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Service to handle Device Migration DeviceClient connections.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class DeviceApiClientService
{
    const VERIFY_CONNECTION_METHOD = 'v1/device/migrate/migrateDevice/verifyConnection';
    const VERIFY_CONNECTION_TIMEOUT = 10;

    /** @var DeviceApiClient */
    private $deviceClient;

    /** @var DeviceApiClientRepository */
    protected $deviceClientRepository;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /**
     * @param DeviceApiClient $deviceClient
     * @param DeviceApiClientRepository $deviceClientRepository
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        DeviceApiClient $deviceClient,
        DeviceApiClientRepository $deviceClientRepository,
        DeviceLoggerInterface $logger
    ) {
        $this->deviceClient = $deviceClient;
        $this->deviceClientRepository = $deviceClientRepository;
        $this->logger = $logger;
    }

    /**
     * Connect to the specified device and authenticate.
     *
     * @param string $ip IP address of the remote device
     * @param string $username
     * @param string $password
     * @param string|null $ddnsDomain Dynamic DNS domain for HTTPS
     */
    public function connect(
        string $ip,
        string $username,
        string $password,
        string $ddnsDomain = null,
        string $sshIp = null
    ) {
        $this->deviceClient->connect($ip, $username, $password, $ddnsDomain, $sshIp);

        // Verify that we can successfully make an API call without any errors.
        $this->deviceClient->call(self::VERIFY_CONNECTION_METHOD, [], self::VERIFY_CONNECTION_TIMEOUT);

        $connectionData = $this->deviceClient->getSerializedConnectionData();
        $this->deviceClientRepository->save($connectionData);
    }

    /**
     * Call an API method on the client and return the result.
     * This is a blocking call since it waits for the server to reply.
     *
     * @param string $method
     * @param array $parameters
     * @param int $timeout Timeout in seconds (0 = default)
     * @return mixed The returned result.
     */
    public function call(string $method, array $parameters = [], int $timeout = 0)
    {
        return $this->getDeviceClient()->call($method, $parameters, $timeout);
    }

    /**
     * Disconnect from the current device and delete all connection information.
     */
    public function disconnect()
    {
        $this->deviceClientRepository->delete();
    }

    /**
     * Return the current DeviceClient with a valid connection.
     * This will either return the current local copy of the DeviceClient,
     * if there is one, or restore the saved copy from disk.
     *
     * @return DeviceApiClient Current DeviceClient with a valid connection.
     */
    public function getDeviceClient(): DeviceApiClient
    {
        if ($this->deviceClient->isConnected()) {
            return $this->deviceClient;
        }

        $connectionData = $this->deviceClientRepository->load();
        $this->deviceClient->restoreConnectionData($connectionData);

        return $this->deviceClient;
    }
}
