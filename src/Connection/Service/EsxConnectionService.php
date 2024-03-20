<?php

namespace Datto\Connection\Service;

use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\EsxHostType;
use Datto\Connection\ManagedObjectConnectionParameters;
use Datto\Log\LoggerFactory;
use Datto\Log\SanitizedException;
use Datto\Utility\Security\SecretString;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;
use Vmwarephp\Extensions\Folder;
use Vmwarephp\ManagedObject;
use Vmwarephp\Vhost;

/**
 * Service class for managing ESX connection objects.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class EsxConnectionService implements ConnectionServiceInterface
{
    /** Constants used by getHypervisorOptions() */
    const LIST_HOSTS = 1;
    const LIST_HOST_OPTIONS = 2;

    /** @var ConnectionService */
    private $connectionService;

    // TODO: consider removing vhost dependency and using libvirt to check connection & hypervisor options
    /** @var Vhost */
    private $vhost;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var bool[] Used to avoid verifyng credentials multiple times. Key = $server . $username . $password */
    private $credentialsVerified;

    /**
     * @param ConnectionService|null $connectionService
     * @param Vhost|null $vhost
     * @param DeviceLoggerInterface|null $logger
     */
    public function __construct(
        ConnectionService $connectionService = null,
        Vhost $vhost = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->connectionService = $connectionService ?: new ConnectionService();
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->vhost = $vhost;// can be null
        $this->credentialsVerified = [];
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $params)
    {
        $server = array_key_exists('server', $params) ? $params['server'] : '';
        $username = array_key_exists('username', $params) ? $params['username'] : '';
        $password = array_key_exists('password', $params) ? $params['password'] : '';

        $secretPassword = new SecretString($password);
        $this->verifyCredentials($server, $username, $secretPassword);
    }

    /**
     * Creates a new ESX connection, but does not persist it.
     *
     * @param string $name
     *
     * @return EsxConnection
     */
    public function create($name)
    {
        return $this->connectionService->create($name, ConnectionType::LIBVIRT_ESX());
    }

    /**
     * Deletes ESX connection.
     * @return bool true if successful
     */
    public function delete(AbstractLibvirtConnection $connection): bool
    {
        $name = $connection->getName();

        if (!$connection instanceof EsxConnection) {
            $message = "Cannot delete connection \"$name\", not an instance of EsxConnection.";
            $this->logger->error('ECS0008 Cannot delete connection. Not an EsxConnection', ['name' => $name]);
            throw new Exception($message);
        }

        return $this->connectionService->delete($connection);
    }

    /**
     * Checks if the ESX connection with a given name exists.
     */
    public function exists(string $name): bool
    {
        try {
            $existing = $this->get($name);
        } catch (Exception $e) {
            $existing = null;
        }

        return $existing !== null;
    }

    /**
     * Gets the ESX connection with a given name.
     * @return AbstractLibvirtConnection|EsxConnection|null
     */
    public function get(string $name): ?AbstractLibvirtConnection
    {
        $connectionFile = $this->connectionService->getExistingConnectionFile($name, ConnectionType::LIBVIRT_ESX());

        if ($connectionFile === null) {
            return null;
        }

        $connection = $this->connectionService->getFromFile($connectionFile);

        if (!$connection->isValid()) {
            $message = "Specified connection \"$name\" is invalid.";
            $this->logger->error('ECS0009 Specified connection is invalid', ['name' => $name]);
            throw new Exception($message);
        }

        return $connection;
    }

    /**
     * Gets all ESX connections available.
     *
     * @return EsxConnection[]
     */
    public function getAll(): array
    {
        $connections = [];
        $connectionFiles = $this->connectionService->getAllConnectionFiles(ConnectionType::LIBVIRT_ESX());

        foreach ($connectionFiles as $connectionFile) {
            $connection = $this->connectionService->getFromFile($connectionFile);

            if ($connection->isValid()) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @inheritdoc
     */
    public function refreshAll(): void
    {
        foreach ($this->getAll() as $connection) {
            try {
                $this->save($connection);
            } catch (Throwable $e) {
                $this->logger->error('ECS0014 Error refreshing connection', [
                    'name' => $connection->getName(),
                    'exception' => $e
                ]);
            }
        }
    }

    /**
     * Fetches hypervisor options.
     *
     * Depending on first argument, it can provide:
     *      LIST_HOSTS - list of available hosts
     *      LIST_HOST_OPTIONS - list of available network offload methods
     *
     * @param int $hypervisorOption
     * @param array $params Required keys are 'server', 'username', and 'password', optional 'host' and 'hostId'
     *
     * @return array
     */
    public function getHypervisorOptions(int $hypervisorOption, array $params): array
    {
        $this->connect($params);

        $output = [];

        switch ($hypervisorOption) {
            case self::LIST_HOSTS:
                $output = $this->getVcenterHosts();
                break;

            case self::LIST_HOST_OPTIONS:
                $hostType = $params['hostType'];
                $hostId = array_key_exists('hostId', $params) ? $params['hostId'] : '';
                $output = $this->getHostOptions($hostId, $hostType);
                break;
        }

        return $output;
    }

    /**
     * Saves ESX connection to a file.
     * @return bool true if successful
     */
    public function save(AbstractLibvirtConnection $connection): bool
    {
        $name = $connection->getName();

        if (!$connection instanceof EsxConnection) {
            throw new Exception("Cannot save connection \"$name\", not an instance of EsxConnection.");
        }

        if (!$connection->isValid()) {
            throw new Exception("Cannot save connection \"$name\", invalid parameters passed.");
        }

        $this->connectionService->setAsPrimaryIfFirst($connection);
        $this->updateConnectionInfo($connection);

        return $connection->saveData();
    }

    /**
     * @param EsxConnection $connection
     * @param array $params Required keys are 'server', 'username', and 'password'
     *
     * @return EsxConnection
     */
    public function setConnectionParams(AbstractLibvirtConnection $connection, array $params): AbstractLibvirtConnection
    {
        if ($params['offloadMethod'] === 'nfs') {
            $connection->setOffloadMethod('nfs');
            $connection->setIscsiHba(null);
            $connection->setDatastore(null);
        } else {
            $connection->setOffloadMethod('iscsi');
            // TODO: when refactoring for above these should be set by one method, as one can't exist without the other
            $connection->setIscsiHba($params['offloadMethod']);
            $connection->setDatastore($params['datastore']);
        }

        $connection->setUser($params['username']);
        $connection->setPassword($params['password']);
        $connection->setHostType($params['hostType']);

        if ($params['hostType'] === 'stand-alone') {
            $esxHost = $params['server'];
            $datacenterPath = 'ha-datacenter';
            $vCenterHost = null;
        } else {
            $esxHost = $params['esxHost'];
            $datacenterPath = $params['datacenter'];
            $vCenterHost = $params['server'];
        }

        if ($params['hostType'] === 'cluster') {
            $cluster = $params['cluster'];
            $clusterId = $params['clusterId'];
            $esxHostId = $params['esxHostId'];
        } else {
            $cluster = null;
            $clusterId = null;
            $esxHostId = null;
        }

        $connection->setClusterPath($cluster);
        $connection->setClusterId($clusterId);
        $connection->setEsxHostPath($esxHost);
        $connection->setHostId($esxHostId);
        $connection->setDatacenterPath($datacenterPath);
        $connection->setVCenterHost($vCenterHost);

        return $connection;
    }

    /**
     *
     * @param EsxConnection $connection
     * @return ManagedObject[]
     */
    public function getDatastores(EsxConnection $connection)
    {
        $password = new SecretString($connection->getPassword());
        $this->verifyCredentials(
            $connection->getPrimaryHost(),
            $connection->getUser(),
            $password
        );

        $datastores = [];

        if ($connection->getHostType() === EsxHostType::STANDALONE) {
            $hosts = $this->vhost->findAllManagedObjects('HostSystem', ['datastore']);
            $host = $hosts ? $hosts[0] : null;
        } else {
            $host = $this->vhost->findManagedObjectByName(
                'HostSystem',
                basename($connection->getEsxHost()),
                ['datastore']
            );
        }

        if ($host) {
            foreach ($host->datastore as $datastore) {
                $datastores[] = $datastore;
            }
        }

        return $datastores;
    }

    /**
     * Returns the api type as reported by ESX serviceContent
     */
    public function getApiType(string $server, string $username, SecretString $password): string
    {
        $this->verifyCredentials($server, $username, $password);
        $serviceContent = $this->vhost->getServiceContent();

        if (!property_exists($serviceContent, 'about') || !property_exists($serviceContent->about, 'apiType')) {
            throw new Exception('Invalid service content provided.');
        }

        return $serviceContent->about->apiType;
    }

    /**
     * Get the vhost object that is currently being used with this connection
     *
     * @return Vhost
     */
    public function getVhost()
    {
        return $this->vhost;
    }

    /**
     * Given a connection name, update the connection info and return the vHost
     *
     * @return Vhost|null
     */
    public function getVhostForConnectionName(string $connectionName)
    {
        $connection = $this->get($connectionName);
        if ($connection) {
            $this->updateConnectionInfo($connection);
            return $this->vhost;
        } else {
            return null;
        }
    }

    /**
     * Creates a new connection with the same name, connection parameters, and IsPrimary property
     */
    public function copy(array $connectionToCopy): bool
    {
        $connection = $this->create($connectionToCopy['name']);
        $this->setConnectionParams($connection, $connectionToCopy['connectionParams']);
        if (array_key_exists('isPrimary', $connectionToCopy['connectionParams'])) {
            $connection->setIsPrimary($connectionToCopy['connectionParams']['isPrimary']);
        }
        return $this->save($connection);
    }

    /**
     * Get ESX hosts that are managed by vCenter but are not part of a cluster.
     *
     * The ESX host must be in 'connected' state.
     */
    private function getVcenterHosts(): array
    {
        $this->logger->debug('ECS0010 Listing all vCenter hosts.');
        $esxHosts = [];
        $ret = $this->vhost->findAllManagedObjects('HostSystem', []);

        foreach ($ret as $host) {
            if ($host->runtime->connectionState == 'connected'
                && $host->parent->getReferenceType() != 'ClusterComputeResource'
            ) {
                $esxHosts[] = $host->name;
            }
        }

        $esxHosts = $this->resolveObjectNames($esxHosts);

        return $esxHosts;
    }

    /**
     * Get data for ESX Host settings.
     *
     * It is used to populate selection choices for things like datastore or
     * iSCSI adapter the ESX host should use for virtualization.
     */
    private function getHostOptions(string $hostId, string $hostType): array
    {
        $this->logger->debug("ECS0011 Getting host options. Host ID is $hostId, host type is $hostType");
        $options = ['hba' => [], 'datastores' => []];

        $host = null;

        switch ($hostType) {
            case EsxHostType::CLUSTER:
                $host = $this->vhost->findOneManagedObject('HostSystem', $hostId, ['datastore']);
                break;
            case EsxHostType::VCENTER:
                $host = $this->vhost->findManagedObjectByName('HostSystem', basename($hostId), ['datastore']);
                break;
            case EsxHostType::STANDALONE:
                $hosts = $this->vhost->findAllManagedObjects('HostSystem', ['datastore']);
                $host = $hosts[0];
                break;
            default:
                $this->logger->error('ECS0013 Unknown host type', ['hostType' => $hostType]);
                throw new Exception('Unknown host type: ' . $hostType);
        }

        foreach ($host->config->storageDevice->hostBusAdapter as $hba) {
            if ($hba instanceof \HostInternetScsiHba) {
                $options['hba'][] = $hba->device;
            }
        }

        foreach ($host->datastore as $ds) {
            $options['datastores'][] = $ds->name;
        }

        return $options;
    }

    /**
     * Resolves host or cluster into its full folder structure
     *
     * @param string $host the host to search
     * @param Folder $rootFolder the 'hostFolder' of the datacenter
     *
     * @return string the path to the host starting at the data center
     *
     */
    public function getFoldersOfHost(string $host, Folder $rootFolder): string
    {
        $foundFolder = null;

        $findHost = function ($folder) use (&$findHost, $host, &$foundFolder) {
            try {
                if ($folder === null) {
                    return;
                }
                foreach ($folder->childEntity as $entity) {
                    $findHost($entity);
                }
            } catch (\Vmwarephp\Exception\Soap $ex) {
                // we get here when childEntity doesn't exist for a folder
                if ($folder->name === $host) {
                    $foundFolder = $folder;
                }
                return;
            }
        };

        $findHost($rootFolder);

        if ($foundFolder === null) {
            throw new Exception("Could not find host");
        }

        $folderParts = [];
        $folder = $foundFolder;

        while ($folder->name !== 'host') { // 'host' indicates the top of the chain
            $folderParts[] = $folder->name;
            $folder = $folder->parent;
        }

        return implode('/', array_reverse($folderParts));
    }

    /**
     * Get the connection parameters for all Datacenters on the given server.
     *
     * @return ManagedObjectConnectionParameters[]
     */
    public function getDatacenters(string $server, string $username, SecretString $password): array
    {
        $this->logger->debug('ECS0003 Getting the list of datacenters for ' . $server);

        $this->verifyCredentials($server, $username, $password);
        $managedObjects = $this->vhost->findAllManagedObjects('Datacenter', ['name', 'parent']);
        $datacenters = [];

        foreach ($managedObjects as $datacenter) {
            $fullPath = $this->resolveDatacenterPath($datacenter);
            $datacenters[] = new ManagedObjectConnectionParameters($fullPath, $datacenter->getReferenceId());
        }
        return $datacenters;
    }

    /**
     * Get the connection parameters for all clusters on the server that are associated with the given datacenter.
     *
     * @return ManagedObjectConnectionParameters[]
     */
    public function getDatacenterClusters(
        string $server,
        string $username,
        SecretString $password,
        string $datacenterId
    ): array {
        $this->logger->debug('ECS0004 Getting the list of clusters on ' . $server . ' for datacenter ' . $datacenterId);
        $this->verifyCredentials($server, $username, $password);
        $datacenter = $this->vhost->findOneManagedObject('Datacenter', $datacenterId, ['name', 'hostFolder']);
        $clusters = $this->traverseFolderForManagedObjects($datacenter->hostFolder, 'ClusterComputeResource');

        return $clusters;
    }

    /**
     * Get ESX hosts that are managed by the specified cluster.
     *
     * The ESX host must be in 'connected' state.
     */
    public function getClusterHosts(
        string $server,
        string $username,
        SecretString $password,
        string $clusterId
    ): array {
        $this->logger->debug('ECS0005 Getting the list of hosts on ' . $server . ' for cluster ' . $clusterId);
        $this->verifyCredentials($server, $username, $password);
        $cluster = $this->vhost->findOneManagedObject('ClusterComputeResource', $clusterId, ['host']);

        $esxHosts = [];

        foreach ($cluster->host as $esxHost) {
            if ($esxHost->runtime->connectionState == 'connected') {
                $esxHosts[] = $esxHost;
            }
        }

        return $esxHosts;
    }

    private function updateConnectionInfo(EsxConnection $esxConnection)
    {
        $user = $esxConnection->getUser();
        $password = new SecretString($esxConnection->getPassword());
        $host = $esxConnection->getPrimaryHost();
        $this->verifyCredentials($host, $user, $password);
        $esxHost = $esxConnection->getEsxHost();
        if ($esxConnection->getHostType() === 'stand-alone') {
            $esxHostVersion = $this->getPrimaryHostInfo(
                $host,
                $user,
                $password,
                'version'
            );
            $esxHostLicenseProductName = $this->getPrimaryHostInfo(
                $host,
                $user,
                $password,
                'licenseProductName'
            );
            $vcenterHostVersion = null;
            $vcenterHostLicenseProductName = null;
        } else {
            $vcenterHostVersion = $this->getPrimaryHostInfo(
                $host,
                $user,
                $password,
                'version'
            );
            $vcenterHostLicenseProductName = $this->getPrimaryHostInfo(
                $host,
                $user,
                $password,
                'licenseProductName'
            );

            $esxHostVersion = $this->getManagedHostInfo(
                $host,
                $esxHost,
                $user,
                $password,
                'version'
            );
            $esxHostLicenseProductName = $this->getManagedHostInfo(
                $host,
                $esxHost,
                $user,
                $password,
                'licenseProductName'
            );
        }

        $esxConnection->setEsxHostVersion($esxHostVersion);
        $esxConnection->setEsxHostLicenseProductName($esxHostLicenseProductName);
        $esxConnection->setVcenterHostVersion($vcenterHostVersion);
        $esxConnection->setVcenterHostLicenseProductName($vcenterHostLicenseProductName);
    }

    /**
     * Resolves the full path name of a datacenter
     *
     * @param ManagedObject $datacenter datacenter name
     *
     * @return string full path to datacenter
     */
    private function resolveDatacenterPath($datacenter)
    {
        $pathParts = [];
        $parent = $datacenter->parent;

        if ($parent->name === 'Datacenters') {
            return $datacenter->name;
        }

        $pathParts[] = $datacenter->name;

        while ($parent->name !== 'Datacenters') {
            $pathParts[] = $parent->name;

            if ($parent->parent !== null) {
                $parent = $parent->parent;
            } else {
                break;
            }
        }

        $reversedOrder = array_reverse($pathParts);
        return implode('/', $reversedOrder);
    }

    /**
     * Takes an array of objects and resolves them all to their proper folder structure
     *
     * @param $objects array of either ESX hosts or clusters
     *
     * @return array hosts or clusters with names resolved to include folder structure
     */
    private function resolveObjectNames($objects)
    {
        $datacenters = $this->vhost->findAllManagedObjects('Datacenter', []);
        $self = $this;

        return array_map(function ($object) use ($datacenters, $self) {
            $objectName = $object;
            foreach ($datacenters as $dc) {
                try {
                    $objectName = $self->getFoldersOfHost($object, $dc->hostFolder);
                } catch (Exception $ex) {
                    continue;
                }
            }
            return $objectName;
        }, $objects);
    }

    /**
     * Checks if one can connect to vCenter/ESX host given credentials.
     *
     * This is called from within all the methods here because it also creates Vhost object instance to work with.
     */
    private function verifyCredentials(string $server, string $username, SecretString $password): void
    {
        $credentialIndex = $server . $username . $password;
        if (isset($this->credentialsVerified[$credentialIndex])) {
            return;
        }

        $this->logger->debug('ECS0001 Verifying connection credentials for ' . $server);
        // only create new Vhost object the first time or when something changed (allows us to unit test it)
        if ($this->vhost === null ||
            $this->vhost->host !== $server ||
            $this->vhost->username !== $username ||
            $this->vhost->password !== $password->getSecret()
        ) {
            $this->vhost = new Vhost($server, $username, $password->getSecret());
        }

        try {
            $this->vhost->connect();
        } catch (Exception $ex) {
            $message = 'Failed to connect to the host. ';

            if (strpos($ex->getMessage(), 'InvalidLogin') !== false) {
                $message .= 'Invalid login credentials provided.';
                $this->logger->error('ECS0002 Failed to connect to the host. Invalid credentials');
            } else {
                $message .= 'Please make sure you have entered a correct host address.';
                $this->logger->error('ECS0012 Failed to connect to the host. Check address.');
            }
            $unsafeException = new Exception($message);
            throw new SanitizedException($unsafeException, [$password]);
        }

        $this->credentialsVerified[$credentialIndex] = true;
    }

    /**
     * Given a ManagedObject reference that is a Folder, traverse the folder structure and build an array of
     * ManagedObjectConnectionParameters for all objects matching the given object type.
     *
     * @return ManagedObjectConnectionParameters[]
     */
    private function traverseFolderForManagedObjects(
        ManagedObject $folder,
        string $objectType,
        string $basePath = ''
    ): array {
        if ($folder->getReferenceType() !== 'Folder') {
            $message = 'Invalid reference type passed as Folder. Given type is ' . $folder->getReferenceType();
            $this->logger->error('ECS0006 Invalid reference type passed as folder', [
                'type' => $folder->getReferenceType()
            ]);
            throw new Exception($message);
        }

        $objects = [];

        foreach ($folder->childEntity as $managedObject) {
            // It is possible for the childEntity of an empty folder to be an array containing one null instance,
            // so we need to be sure to check that we have something here prior to attempting to dereference it
            $referenceType = $managedObject ? $managedObject->getReferenceType() : null;

            if ($referenceType === $objectType) {
                $objectPath = $basePath . $managedObject->name;
                $objects[] = new ManagedObjectConnectionParameters($objectPath, $managedObject->getReferenceId());
            } elseif ($referenceType === 'Folder') {
                $updatedPath = $basePath . $managedObject->name . '/';
                $nestedObjects = $this->traverseFolderForManagedObjects($managedObject, $objectType, $updatedPath);
                $objects = array_merge($objects, $nestedObjects);
            }
        }

        return $objects;
    }

    /**
     * Returns key value for which the request is made as reported by ESX serviceContent
     *  eg vcenter version if vcentered, esx host version if standalone host
     */
    private function getPrimaryHostInfo(string $server, string $username, SecretString $password, string $key): string
    {
        $this->verifyCredentials($server, $username, $password);
        $serviceContent = $this->vhost->getServiceContent();

        if (!property_exists($serviceContent, 'about') || !property_exists($serviceContent->about, $key)) {
            throw new Exception('Invalid service content provided.');
        }

        return $serviceContent->about->$key ?? '';
    }

    /**
     * Returns key value for which the request is made as reported by Vcenter hostSystem
     *  eg vcenter version if vcentered, esx host version if standalone host
     */
    private function getManagedHostInfo(
        string $vServer,
        string $hServer,
        string $username,
        SecretString $password,
        string $key
    ): string {
        $this->verifyCredentials($vServer, $username, $password);
        $hostSystem = $this->vhost->findManagedObjectByName('HostSystem', $hServer, []);

        return $hostSystem->config->product->$key ?? '';
    }
}
