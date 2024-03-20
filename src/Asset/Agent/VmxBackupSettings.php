<?php

namespace Datto\Asset\Agent;

/**
 * Class VmxBackupSettings Agent settings for VMX backup. These settings only apply to Windows agents (not agentless
 * systems) that are running on VMWare hosts.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class VmxBackupSettings
{
    /** @var bool */
    private $enabled;

    /** @var string */
    private $connectionName;

    /**
     * VmxBackupSettings constructor.
     * @param bool $enabled whether or not VMX Backup is enabled
     * @param string $connectionName the hypervisor connection name for VMX backup
     */
    public function __construct(
        bool $enabled = false,
        string $connectionName = ''
    ) {
        $this->enabled = $enabled;
        $this->connectionName = $connectionName;
    }

    /**
     * @return string the the hypervisor connection name for VMX backup
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * @param string $name set the hypervisor connection name for VMX backup
     */
    public function setConnectionName(string $name): void
    {
        $this->connectionName = $name;
    }

    /**
     * @return bool whether or not VMX backup is enabled
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled set whether or not VMX backup is enabled
     */
    public function setEnabled($enabled): void
    {
        $this->enabled = $enabled;
    }
}
