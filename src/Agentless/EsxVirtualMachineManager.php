<?php

namespace Datto\Agentless;

use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetType;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\EsxHostType;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerFactory;
use Datto\Util\Sanitizer;
use Datto\Log\DeviceLoggerInterface;
use Vmwarephp\Extensions\VirtualMachine;
use Vmwarephp\ManagedObject;

/**
 * Class for managing Virtual Machines on an ESX connection
 * @author Christopher Bitler <cbitler@datto.com>
 */
class EsxVirtualMachineManager
{
    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var AgentService */
    private $agentService;

    /** @var FeatureService */
    private $featureService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param EsxConnectionService|null $esxConnectionService
     * @param AgentService|null $agentService
     * @param FeatureService|null $featureService
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        EsxConnectionService $esxConnectionService = null,
        AgentService $agentService = null,
        FeatureService $featureService = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->esxConnectionService = $esxConnectionService ?: new EsxConnectionService();
        $this->agentService = $agentService ?: new AgentService();
        $this->featureService = $featureService ?: new FeatureService();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * Get a list of the virtual machines hosted on the given server.
     *
     * @return \Vmwarephp\Extensions\VirtualMachine[] array of virtual machines hosted on the server
     */
    public function getVirtualMachines()
    {
        return $this->esxConnectionService->getVhost()->findAllManagedObjects('VirtualMachine', array(
            'name',
            'config',
            'guest',
            'runtime',
            'resourcePool'
        ));
    }

    /**
     * Retrieves the virtual machines within a cluster on a given server based on the clusterID
     *
     * @param string $clusterId The reference ID of the cluster
     *
     * @return \Vmwarephp\Extensions\VirtualMachine[] List of VMs in the cluster
     */
    public function getVirtualMachinesInCluster($clusterId)
    {
        $cluster = $this->esxConnectionService->getVhost()->findOneManagedObject(
            'ClusterComputeResource',
            $clusterId,
            array('host')
        );
        $vmsRetrieved = array();

        foreach ($cluster->host as $host) {
            $hostRef = $host->getReferenceId();
            $host = $this->esxConnectionService->getVhost()->findOneManagedObject('HostSystem', $hostRef, array('vm'));

            $vms = $this->requestVirtualMachinesFromHostSystem($host);
            foreach ($vms as $vm) {
                $vmsRetrieved[] = $vm;
            }
        }

        return $vmsRetrieved;
    }

    /**
     * Retrieves the virtual machines attached to a specific host given a host reference ID
     *
     * @param string $hostId The reference ID of the host
     *
     * @return \Vmwarephp\Extensions\VirtualMachine[] List of VMs in the host
     */
    public function getVirtualMachinesOnHost($hostId)
    {
        $host = $this->esxConnectionService->getVhost()->findOneManagedObject('HostSystem', $hostId, array('vm'));

        return $this->requestVirtualMachinesFromHostSystem($host);
    }

    /**
     * Get the list of VMs attached to a specific connection by connection name
     * This determines the connection type, such as being connected to a cluster or only to a host, or a standalone connection
     * and will pick the method for retrieving VMs accordingly
     *
     * @param string $connectionName Name of the connection
     *
     * @return array List of virtual machines tied to the connection
     */
    public function getAvailableVirtualMachinesForConnection($connectionName)
    {
        $connection = $this->esxConnectionService->get($connectionName);
        $availableVms = array();
        $name = $connection->getName();

        $isCluster = $connection->getHostType() === EsxHostType::CLUSTER;
        $isStandAlone = $connection->getHostType() === EsxHostType::STANDALONE;

        $this->esxConnectionService->connect([
            'server' => $connection->getPrimaryHost(),
            'username' => $connection->getUser(),
            'password' => $connection->getPassword()
        ]);

        $this->setHostAndClusterIdIfNull($connection, $isCluster, $isStandAlone);

        // We get the virtual machines based on the host type.
        try {
            if ($isCluster) {
                $allVms = $this->getVirtualMachinesInCluster(
                    $connection->getClusterId()
                );
            } elseif ($isStandAlone) {
                $allVms = $this->getVirtualMachines();
            } else {
                $allVms = $this->getVirtualMachinesOnHost(
                    $connection->getHostId()
                );
            }
        } catch (\Throwable $throwable) {
            $this->logger->error('EVM0001 Error retrieving VMs for hypervisor connection', ['exception' => $throwable]);
            throw $throwable;
        }

        $agentlessSystems = $this->agentService->getAllActiveLocal(AssetType::AGENTLESS);

        $connectionVms = [];
        foreach ($allVms as $vm) {
            $moRef = $vm->getReferenceId();
            $vmName = $vm->name;

            $keyName = sprintf(
                '%s-%s',
                $moRef,
                Sanitizer::agentName($vmName)
            );

            if ($this->agentService->isAgentlessSystemPaired($connection->getName(), $moRef, $agentlessSystems)) {
                continue;
            }

            $vmConfig = $vm->config;
            $vAppConfig = $vmConfig ? $vmConfig->vAppConfig : null;
            $vmRuntime = $vm->runtime;

            $os = $vmConfig ? $vmConfig->guestFullName : '';
            $compatible = $this->isCompatibleVM($os);

            $vmInfo = [
                'keyName' => $keyName,
                'moRef' => $moRef,
                'name' => $vmName,
                'ip' => $vm->guest->ipAddress,
                'os' => $os,
                'connectionName' => $connection->getName(),
                'generic' => !$compatible,
                'fullDisk' => false,
            ];

            $productName = $vAppConfig ? $vAppConfig->product[0]->name : null;
            $isDattoDevice = $productName === 'Datto Virtual Device' || strpos($productName, 'SIRIS') !== false;

            if ($vmRuntime->connectionState == 'connected'
                && $vm->resourcePool !== null
                && $isDattoDevice === false
            ) {
                $connectionVms[] = $vmInfo;
            }
        }

        usort($connectionVms, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $availableVms[] = array(
            'connection' => $name,
            'VMs' => $connectionVms,
        );
        return $availableVms;
    }

    /**
     * Check if the provided info is compatible with currently established requirements
     *
     * @param string $vmOS The operating system that is running on the virtual machine
     *
     * @return bool
     */
    private function isCompatibleVM(string $vmOS): bool
    {
        return AgentlessSystem::isOperatingSystemFullySupported($vmOS);
    }

    /**
     * Get the reference ID of a cluster so that it can be saved to the connection settings
     * This usually exists on hypervisor connection creation, but for old hypervisor connections it may not.
     *
     * @param string $cluster
     *
     * @return string Reference ID for the cluster
     */
    private function getClusterId($cluster)
    {
        $cluster = $this->esxConnectionService->getVhost()->findManagedObjectByName(
            'ClusterComputeResource',
            $cluster,
            array()
        );
        return $cluster->getReferenceId();
    }

    /**
     * Get the reference ID of a host so that it can be saved to the connection settings
     * This usually exists on hypervisor connection creation, but for old hypervisor connections it may not.
     *
     * @param string $host
     *
     * @return string Reference ID for the host
     */
    private function getHostId($host)
    {
        $hostSystem = $this->esxConnectionService->getVhost()->findManagedObjectByName('HostSystem', $host, array());
        return $hostSystem->getReferenceId();
    }

    /**
     * Set the cluster and host ID on a connection if they do not exist
     * This is necessary for Esx connections established prior to the release of CP-10634
     *
     * @param EsxConnection $connection
     * @param bool $isCluster
     * @param bool $isStandAlone
     */
    private function setHostAndClusterIdIfNull(EsxConnection $connection, $isCluster, $isStandAlone): void
    {
        if ($connection->getClusterId() === null && $isCluster) {
            $clusterId = $this->getClusterId(
                $connection->getCluster()
            );
            $connection->setClusterId($clusterId);
            $connection->saveData();
        }
        if ($connection->getHostId() === null && !$isStandAlone) {
            $hostId = $this->getHostId(
                $connection->getEsxHost()
            );
            $connection->setHostId($hostId);
            $connection->saveData();
        }
    }

    /**
     * Retrieves a list of virtual machines that are attached to that the specified host
     *
     * @param ManagedObject $hostSystem The object representing the host to retrieve VMs from
     *
     * @return VirtualMachine[] List of virtual machines in the HostSystem
     */
    private function requestVirtualMachinesFromHostSystem($hostSystem)
    {
        $vmsRetrieved = array();
        foreach ($hostSystem->vm as $vm) {
            // If the host system is in maintenance mode or has no VMs,
            // the "vm" array can contain a null entry (see JIRA CP-12127).
            // This appears to be an undocumented "feature" of libvert.
            if ($vm) {
                $refId = $vm->getReferenceId();

                // We re-request the VM here in order to get all of the properties that we need in one web request.
                // If we didn't do this this way, Vmwarephp would send a request out for each property to get it
                // when we needed to use it.
                $vm = $this->esxConnectionService->getVhost()->findOneManagedObject(
                    'VirtualMachine',
                    $refId,
                    array(
                        'name',
                        'config',
                        'guest',
                        'runtime',
                        'resourcePool'
                    )
                );
                $vmsRetrieved[] = $vm;
            }
        }

        return $vmsRetrieved;
    }
}
