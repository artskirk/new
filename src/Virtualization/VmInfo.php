<?php

namespace Datto\Virtualization;

use Datto\Config\JsonConfigRecord;
use Datto\Connection\ConnectionType;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmInfo extends JsonConfigRecord
{
    /** @var ConnectionType */
    private $type;

    /** @var string **/
    private $connectionName = '';

    /** @var string */
    private $name = '';

    /** @var string */
    private $uuid = '';

    /**
     * @param string $vmName
     * @param string $connectionName
     * @param ConnectionType|null $connectionType
     */
    public function __construct(string $vmName = '', string $connectionName = '', $connectionType = null)
    {
        $this->name = $vmName;
        $this->connectionName = $connectionName;
        $this->type = $connectionType ?? ConnectionType::LIBVIRT_KVM();
    }

    /**
     * @inheritdoc
     */
    public function getKeyName(): string
    {
        return 'vmInfo';
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'type' => $this->getType()->value(),
            'connectionName' => $this->getConnectionName(),
            'name' => $this->getName(),
            'uuid' => $this->getUuid()
        ];
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals)
    {
        $this->setType(ConnectionType::memberByValue($vals['type']));
        $this->setConnectionName($vals['connectionName'] ?? '');
        $this->setName($vals['name'] ?? '');
        $this->setUuid($vals['uuid'] ?? '');
    }

    /**
     * @return string Libvirt vm uuid
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * @param string $uuid
     * @return $this
     */
    public function setUuid(string $uuid): VmInfo
    {
        $this->uuid = $uuid;
        return $this;
    }

    /**
     * @return string Libvirt vm name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): VmInfo
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string Libvirt connection name
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @param string $connectionName
     * @return $this
     */
    public function setConnectionName(string $connectionName): VmInfo
    {
        $this->connectionName = $connectionName;
        return $this;
    }

    /**
     * @return ConnectionType Libvirt connection type
     */
    public function getType(): ConnectionType
    {
        return $this->type;
    }

    /**
     * @param ConnectionType $type
     * @return $this
     */
    public function setType(ConnectionType $type): VmInfo
    {
        $this->type = $type;
        return $this;
    }
}
