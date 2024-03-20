<?php

namespace Datto\Verification;

use Datto\Asset\Agent\Agent;
use Datto\Cloud\JsonRpcClient;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Service\HvConnectionService;
use Datto\Log\LoggerAwareTrait;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Controls the creation of dynamic verification connections.
 */
class CloudAssistedVerificationOffloadService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DYNAMIC_CONNECTION_NAME_PREFIX = 'dynamic_';
    const GET_HYPERVISOR_INFO_ENDPOINT = 'v1/device/asset/agent/screenshot/getHypervisorInfo';

    /** @var HvConnectionService */
    private $hvConnectionService;

    /** @var JsonRpcClient */
    private $client;

    public function __construct(
        HvConnectionService $hvConnectionService,
        JsonRpcClient $client
    ) {
        $this->hvConnectionService = $hvConnectionService;
        $this->client = $client;
    }

    /**
     * Build a new connection with information retrieved from device-web.
     *
     * @param Agent $agent
     * @return HvConnection
     */
    public function createConnection(Agent $agent): HvConnection
    {
        $connectionInfo = $this->requestConnectionInfo($agent);

        try {
            return $this->createConnectionFromInfo($agent, $connectionInfo);
        } catch (\Throwable $e) {
            // If we can't connect, then inform device-web that we're not using the hypervisor
            $this->releaseConnection($agent);
            throw $e;
        }
    }

    /**
     * Instructs deviceweb to release a hyperv connection once verification completes
     */
    public function releaseConnection(Agent $agent)
    {
        $this->logger->debug('VER0901 Releasing hypervisor connection via deviceweb', [
            "assetKey" => $agent->getKeyName()
        ]);
        $assetKey = $agent->getKeyName();
        $connectionName = self::DYNAMIC_CONNECTION_NAME_PREFIX . $assetKey;

        $connection = $this->hvConnectionService->get($connectionName);
        if ($connection !== null) {
            $connection->deleteData();
        } else {
            $this->logger->debug('VER0903 Local connection was not found to delete');
        }

        $this->client->notifyWithId('v1/device/asset/agent/screenshot/releaseHypervisor', ['assetKey' => $assetKey]);
        $this->logger->debug('VER0902 An attempt was made to release the hypervisor; this may or may not have succeeded.');
    }


    /**
     * Create a new temporary hypervisor connection in memory without saving the connection information to disk.
     *
     * @param Agent $agent
     * @param array $connectionInfo
     *
     * @return HvConnection
     */
    private function createConnectionFromInfo(Agent $agent, array $connectionInfo): HvConnection
    {
        $connectionName = self::DYNAMIC_CONNECTION_NAME_PREFIX . $agent->getKeyName();
        $this->logger->debug("SCN6000 Creating dynamic hypervisor connection $connectionName");

        try {
            $connection = $this->hvConnectionService->createAndConfigure($connectionName, $connectionInfo);
            $connection->saveData();
        } catch (Throwable $throwable) {
            throw new Exception('Could not connect to hypervisor with the provided information.', 0, $throwable);
        }

        return $connection;
    }

    /**
     * Ask device-web which hypervisor this agent should use for an offloaded screenshot verification.
     *
     * @param Agent $agent
     *
     * @return array
     */
    private function requestConnectionInfo(Agent $agent): array
    {
        $connectionInfo = $this->client->queryWithId(self::GET_HYPERVISOR_INFO_ENDPOINT, [
            'assetKey' => $agent->getKeyName()
        ]);
        $connectionInfo['domain'] = $connectionInfo['loginDomain'];
        $connectionInfo['server'] = $connectionInfo['address'];
        unset($connectionInfo['loginDomain']);
        unset($connectionInfo['address']);

        $this->logger->debug("SCN6001 Received connection info for hypervisor {$connectionInfo['server']}");
        return $connectionInfo;
    }
}
