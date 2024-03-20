<?php
namespace Datto\Asset\Agent\Agentless;

use Datto\Asset\Agent\AgentException;
use Datto\Config\AgentConfig;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Log\LoggerFactory;
use Datto\Virtualization\VhostFactory;
use Datto\Log\DeviceLoggerInterface;
use Exception;
use Vmwarephp\Exception\Soap;
use Vmwarephp\Vhost;

/**
 * Points an existing agentless agent that was moved to its new location.
 *
 * @author Peter Salu <psalu@datto.com>
 */
class RetargetAgentlessService
{
    /** @var string */
    private $assetKey;

    /** @var VhostFactory */
    private $vhostFactory;

    /** @var AgentConfig */
    private $agentConfig;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param string $assetKey
     * @param VhostFactory|null $vhostFactory
     * @param AgentConfig|null $agentConfig
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        string $assetKey,
        VhostFactory $vhostFactory = null,
        AgentConfig $agentConfig = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->assetKey = $assetKey;
        $this->vhostFactory = $vhostFactory ?: new VhostFactory();
        $this->agentConfig = $agentConfig ?: new AgentConfig($this->assetKey);
        $this->logger = $logger ?: LoggerFactory::getAssetLogger($this->assetKey);
    }

    /**
     * Retarget an agentless system to a new Esx host.
     *
     * @param string $morefID The new morefID of the agent.
     * @param string $connectionName The new Esx connection.
     */
    public function retarget(string $morefID, string $connectionName): void
    {
        $esxInfo = $this->getEsxInfo();

        $this->validateParameters($morefID, $connectionName);

        $esxInfo['moRef'] = $morefID;
        $esxInfo['connectionName'] = $connectionName;

        $this->setEsxInfo($esxInfo);
    }

    /**
     * @param string $moRef MoRefID of the new target system
     * @param string $connectionName ESX connection of the new system
     * @return bool true if the proposed new target is the same VM as the existing target; otherwise false
     */
    public function verifyTargetIdentity(string $moRef, string $connectionName):bool
    {
        $currentTargetUuids = $this->getCurrentTargetUuids();
        $newTargetUuids = $this->getNewTargetUuids($moRef, $connectionName);

        $uuidsAreDifferent = array_diff($currentTargetUuids, $newTargetUuids) ||
            array_diff($newTargetUuids, $currentTargetUuids);

        return !$uuidsAreDifferent;
    }

    /**
     * @param string $morefID
     * @param string $connectionName
     */
    protected function validateParameters(string $morefID, string $connectionName): void
    {
        $connectionService = new ConnectionService();
        $connection = $connectionService->get($connectionName);

        if ($connection === null) {
            throw new \InvalidArgumentException("Invalid connection name: $connectionName");
        }

        if (!$connection instanceof EsxConnection) {
            throw new Exception('Connection type is invalid. This method only supports ESX connections');
        }

        $vhost = new Vhost($connection->getPrimaryHost(), $connection->getUser(), $connection->getPassword());

        try {
            $vhost->findOneManagedObject('VirtualMachine', $morefID, []);
        } catch (Soap $exception) {
            throw new AgentlessSystemNotFoundException("Unable to find the system with morefid: $morefID");
        }
    }

    /**
     * @return string[] Disk Uuids of the current target system
     */
    private function getCurrentTargetUuids():array
    {
        $esxInfo = $this->getEsxInfo();
        $uuids = [];
        foreach ($esxInfo['vmdkInfo'] as $existingAssetDisk) {
            $uuids[] = $existingAssetDisk['diskUuid'];
        }
        return $uuids;
    }

    /**
     * @param string $moRef MoRefID of the new target system
     * @param string $connectionName ESX connection of the new system
     * @return string[] Disk Uuids of the proposed new target system
     */
    private function getNewTargetUuids(string $moRef, string $connectionName):array
    {
        $vhost = $this->vhostFactory->create($connectionName);
        $vhost->connect();
        $vm = $vhost->findOneManagedObject('VirtualMachine', $moRef, ['config']);

        $uuids = [];
        foreach ($vm->config->hardware->device ?? [] as $device) {
            $uuid = $device->backing->uuid ?? null;
            if ($uuid) {
                $uuids[] = $uuid;
            }
        }
        return $uuids;
    }

    /**
     * @return array The contents of the assetKey.esxInfo file.
     */
    private function getEsxInfo()
    {
        $esxInfoRaw = $this->agentConfig->get(EsxInfo::KEY_NAME);

        if ($esxInfoRaw === false) {
            $message = "Unable to read $this->assetKey." . EsxInfo::KEY_NAME;
            $this->logger->info('AGL0010 Unable to read esxInfo file', ['assetKey' => $this->assetKey]);
            throw new AgentException($message);
        }

        $esxInfo = unserialize($esxInfoRaw, ['allowed_classes' => false]);

        if (!is_array($esxInfo)) {
            $message = "Error unserializing $this->assetKey." . EsxInfo::KEY_NAME;
            $this->logger->info('AGL0011 Error unserializing esxInfo key file', ['assetKey' => $this->assetKey]);
            throw new AgentException($message);
        }

        return $esxInfo;
    }

    /**
     * Update the contents of the assetKey.esxInfo file.
     *
     * @param array $esxInfo
     */
    private function setEsxInfo(array $esxInfo): void
    {
        $esxInfoSerialized = serialize($esxInfo);
        $this->agentConfig->set(EsxInfo::KEY_NAME, $esxInfoSerialized);
    }
}
