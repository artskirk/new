<?php

namespace Datto\Connection\Service;

use Datto\Asset\Agent\Agentless\EsxInfo;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\ConnectionInterface;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\Connection\StorageBackedConnectionInterface;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Metadata\FileAccess\FileAccess;
use Datto\Metadata\FileAccess\FileType;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Base class for various connection service. Can be used as standalone, convenience class for fetching arbitrary
 * connections.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class ConnectionService
{
    const CONNECTION_DIR = '/datto/config/connections/';
    const ESX_INFO_PATTERN = '/datto/config/keys/*.esxInfo';
    const HV_INFO_PATTERN = '/datto/config/keys/*.hvInfo';

    private AgentConfigFactory $agentConfigFactory;
    private Filesystem $filesystem;
    private ?FileAccess $fileAccess;
    private DeviceLoggerInterface $logger;

    public function __construct(
        ?AgentConfigFactory    $agentConfigFactory = null,
        ?Filesystem            $filesystem = null,
        ?FileAccess            $fileAccess = null,
        ?DeviceLoggerInterface $logger = null
    ) {
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->fileAccess = $fileAccess;// can be null
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * @param $name
     * @param ConnectionType $type
     *
     * @return EsxConnection|HvConnection|KvmConnection
     */
    public function create(?string $name, ConnectionType $type)
    {
        if (!is_string($name)) {
            $this->logger->error('ECS0015 Attempted to create connection with non string name');
            throw new Exception("Attempted to create connection with non string name.");
        }

        if ($type === ConnectionType::LIBVIRT_KVM()) {
            return new KvmConnection();
        }

        if ($this->get($name)) {
            $this->logger->error('ECS0007 Attempted to create connection with existing name', ['name' => $name]);
            throw new Exception("Connection $name already exists.");
        }

        if ($type === ConnectionType::LIBVIRT_ESX()) {
            $connection = new EsxConnection($name);
        } elseif ($type === ConnectionType::LIBVIRT_HV()) {
            $connection = new HvConnection($name);
        } else {
            throw new Exception('Invalid hypervisor type.');
        }

        $this->setStorageBackend($connection);
        return $connection;
    }

    /**
     * Instantiates a hypervisor connection provider based on passed arguments.
     *
     * All the arguments are optional, and the creation rules are as follows:
     *
     *  - find matching user-defined connection in the config directory based
     *    on the name and/or type passed and pre-load it with the data.
     *  - if multiple user-defined connections match the search criteria, the
     *    one that is set as 'primary' will be returned (there should be only one)
     *  - if there is no pre-configured connection match by specified name/type,
     *    return a new instance of that connection, with 'blank' data in it.
     *    In that case, use isValid connection method to check if it has any
     *    potentially useful data in it.
     *  - if no connection name and type is specified, an there are no pre-defined
     *    connections, the local hypervisor connection will be returned
     *
     * This method will always succeed in returning the instance of the
     * requested connection. If the connection data is not present in the
     * config file a new blank connection will be returned which the calling
     * code can fill with the data it needs.
     *
     * @param string $name
     *  (Optional) A pre-configured connection name.
     * @param ConnectionType $type
     *  (Optional) A connection type.
     *
     * @return ConnectionInterface
     */
    public function find(string $name = null, ConnectionType $type = null): ConnectionInterface
    {
        $connectionByName = null;
        $connectionByType = null;
        $connectionPrimary = null;
        $returnedConnection = null;

        if ($name !== null && $name !== "") {
            $connectionByName = $this->get($name);
        }

        if ($type !== null && $connectionByName === null) {
            $connectionByType = $this->create($name, $type);
        }

        if ($connectionByName === null && $connectionByType === null) {
            $connectionPrimary = $this->getPrimary();
        }

        $connectionLocal = $this->getLocal();

        if ($connectionByName !== null) {
            $returnedConnection = $connectionByName;
        } elseif ($connectionByType !== null) {
            $returnedConnection = $connectionByType;
        } elseif ($connectionPrimary !== null) {
            $returnedConnection = $connectionPrimary;
        } else {
            $returnedConnection = $connectionLocal;
        }

        // If invalid, revert to local connection
        if (!$returnedConnection->isValid()) {
            $returnedConnection = $connectionLocal;
        }

        return $returnedConnection;
    }

    public function delete(AbstractLibvirtConnection $connection): bool
    {
        $wasPrimary = $connection->isPrimary();

        if ($connection instanceof StorageBackedConnectionInterface) {
            $connection->deleteData();
        }

        if ($wasPrimary) {
            $this->setFirstAsPrimary();
        }

        return true;
    }

    /**
     * @param string $name
     *
     * @return AbstractLibvirtConnection|null
     */
    public function get($name)
    {
        $connection = null;
        $connectionFile = $this->getExistingConnectionFile($name, ConnectionType::LIBVIRT_ESX());
        if ($connectionFile) {
            $connection = $this->getFromFile($connectionFile);
        }

        if ($connection === null) {
            $connectionFile = $this->getExistingConnectionFile($name, ConnectionType::LIBVIRT_HV());
            if ($connectionFile) {
                $connection = $this->getFromFile($connectionFile);
            }
        }

        // last ditch attempt, might be local
        if (null === $connection) {
            $localConnection = $this->getLocal();

            if ($localConnection->getName() === $name) {
                $connection = $localConnection;
            }
        }

        return $connection;
    }

    /**
     * @return AbstractLibvirtConnection[]
     */
    public function getAll(): array
    {
        $esxConnectionFiles = $this->getAllConnectionFiles(ConnectionType::LIBVIRT_ESX());
        $hvConnectionFiles = $this->getAllConnectionFiles(ConnectionType::LIBVIRT_HV());
        $connectionFiles = array_merge($esxConnectionFiles, $hvConnectionFiles);

        foreach ($connectionFiles as $connectionFile) {
            $connection = $this->getFromFile($connectionFile);

            if ($connection->isValid()) {
                $connections[] = $connection;
            }
        }

        return $connections ?? [];
    }

    /**
     * Retrieve the local hypervisor connection (KVM)
     */
    public function getLocal(): AbstractLibvirtConnection
    {
        return new KvmConnection();
    }

    public function getExistingConnectionFile(string $name, ConnectionType $type): ?string
    {
        $name = AbstractLibvirtConnection::sanitizeFileName($name);
        $this->filesystem->mkdirIfNotExists(self::CONNECTION_DIR, true, 0777);

        $filename = self::CONNECTION_DIR . $name . '.' . $type->value();
        if ($this->filesystem->exists($filename)) {
            return $filename;
        }

        return null;
    }

    public function getAllConnectionFiles(?ConnectionType $type = null): array
    {
        $this->filesystem->mkdirIfNotExists(self::CONNECTION_DIR, true, 0777);

        $pattern = self::CONNECTION_DIR . '*';

        if ($type !== null) {
            $pattern .= '.' . $type->value();
        }
        // Silencing intentional
        $ret = $this->filesystem->glob($pattern);
        if ($ret) {
            return $ret;
        } else {
            return [];
        }
    }

    /**
     * Creates connection instance from a file.
     *
     * @param string $filePath
     *
     * @return AbstractLibvirtConnection|StorageBackedConnectionInterface
     */
    public function getFromFile(string $filePath)
    {
        $name = pathinfo($filePath, PATHINFO_FILENAME);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        switch ($ext) {
            case ConnectionType::LIBVIRT_ESX()->value():
                $connection = new EsxConnection($name);
                break;

            case ConnectionType::LIBVIRT_HV()->value():
                $connection = new HvConnection($name);
                break;

            default:
                throw new Exception('Hypervisor type does not support loading from file.');
        }

        $this->setStorageBackend($connection);

        $connection->loadData();

        return $connection;
    }

    /**
     * Returns primary connection. Ignores local connections, as they are indirectly becoming a primary connection.
     */
    public function getPrimary(): ?AbstractLibvirtConnection
    {
        $connectionPrimary = null;
        $existing = $this->getAll();

        foreach ($existing as $connection) {
            if ($connection->isPrimary()) {
                $connectionPrimary = $connection;
                break;
            }
        }

        return $connectionPrimary;
    }

    /**
     * Local connections are a bit of an odd duck. They are not explicitly set as primary, but become primary when
     * there are no remote hypervisor connections present or no remote is set to primary.
     */
    public function isLocalPrimary(): bool
    {
        $isLocalPrimary = true;
        $existing = $this->getAll();

        foreach ($existing as $connection) {
            if ($connection->isPrimary()) {
                $isLocalPrimary = false;
                break;
            }
        }

        return $isLocalPrimary;
    }

    /**
     * Sets connection as primary and un-primaries previously primary connection.
     */
    public function setAsPrimary(AbstractLibvirtConnection $connection)
    {
        $existingConnections = $this->getAll();

        foreach ($existingConnections as $existingConnection) {
            if ($existingConnection->isPrimary()) {
                $existingConnection->setIsPrimary(false);

                if ($existingConnection instanceof StorageBackedConnectionInterface) {
                    $existingConnection->saveData();
                }
            }
        }

        $connection->setIsPrimary(true);

        if ($connection instanceof StorageBackedConnectionInterface) {
            $connection->saveData();
        }
    }

    /**
     * Sets connection as primary if there are no other connections (e.g. this would be the first one).
     *
     */
    public function setAsPrimaryIfFirst(AbstractLibvirtConnection $connection): bool
    {
        $setAsPrimary = false;
        $local = $this->getLocal();
        $existing = $this->getAll();

        // todo: getLocal no longer returns null, so this function will not update the primary connection
        // todo: when this function is updated, update the corresponding unit tests!
        $needsSettingAsPrimary = $local === null && count($existing) === 0;

        if ($needsSettingAsPrimary) {
            $connection->setIsPrimary(true);
            $setAsPrimary = true;
        }

        return $setAsPrimary;
    }

    /**
     * Sets first found connection existing as primary.
     *
     */
    public function setFirstAsPrimary(): bool
    {
        $setFirstAsPrimary = false;
        $local = $this->getLocal();
        $existing = $this->getAll();

        // todo: getLocal no longer returns null, so this function will not update the primary connection
        // todo: when this function is updated, update the corresponding unit tests!
        $needsSettingAsPrimary = $local === null && count($existing) > 0;

        if ($needsSettingAsPrimary) {
            $newPrimary = $existing[0];
            $newPrimary->setIsPrimary(true);

            // for those that need an explicit save
            if ($newPrimary instanceof StorageBackedConnectionInterface) {
                $newPrimary->saveData();
            }

            $setFirstAsPrimary = true;
        }

        return $setFirstAsPrimary;
    }

    /**
     * For connections that need storage backend, inject FileAccess.
     *
     * @psalm-suppress RedundantCondition
     */
    public function setStorageBackend(StorageBackedConnectionInterface $connection): void
    {
        // the following ugliness is for unit testing sake
        if ($this->fileAccess) {
            $storage = $this->fileAccess;
        } else {
            FileAccess::setPath(self::CONNECTION_DIR);
            $storage = new FileAccess();
            $storage->setType(FileType::JSON());
        }

        $connection->setStorageBackend($storage);
    }

    /**
     * Updates all the agentless system's esxInfo file connected to a specific connection name
     * with the new connection name
     * @param string $oldConnectionName
     * @param string $newConnectionName
     */
    public function updateAgentlessConnectionName($oldConnectionName, $newConnectionName)
    {
        $agents = $this->agentConfigFactory->getAllKeyNames();
        foreach ($agents as $agent) {
            $agentConfig = $this->agentConfigFactory->create($agent);
            if ($agentConfig->has(EsxInfo::KEY_NAME)) {
                $esxInfo = unserialize($agentConfig->get(EsxInfo::KEY_NAME), ['allowed_classes' => false]);
                $agentConnection = $esxInfo['connectionName'] ?? '';
                if ($agentConnection === $oldConnectionName) {
                    $this->logger->debug("HYP1001 Changing hypervisor connection name for agent $agent " .
                        "from '$oldConnectionName' to '$newConnectionName'");
                    $esxInfo['connectionName'] = $newConnectionName;
                    $agentConfig->set(EsxInfo::KEY_NAME, serialize($esxInfo));
                }
            }
        }
    }
}
