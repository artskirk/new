<?php

namespace Datto\Virtualization\Libvirt;

use Datto\Asset\Agent\Backup\AgentSnapshot;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Virtualization\Hypervisor\Config\AbstractVmSettings;

/**
 * Data context used to build a VmDefinition instance
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class VmDefinitionContext
{
    /** @var AgentSnapshot */
    private $agentSnapshot;

    /** @var string */
    private $name;

    /** @var VmHostProperties */
    private $vmHostProperties;

    /** @var AbstractVmSettings */
    private $vmSettings;

    /** @var OperatingSystem */
    private $guestOs;

    /** @var VirtualDisks */
    private $disks;

    /** @var int */
    private $vncPort = 0;

    /** @var string */
    private $vncPassword = '';

    /** @var bool */
    private $modernEnvironment;

    /** @var bool */
    private $serialPortRequired = false;

    /** @var bool */
    private $hasNativeConfiguration = false;

    /**
     * @param AgentSnapshot $agentSnapshot
     * @param string $name
     * @param VmHostProperties $vmHostProperties
     * @param AbstractVmSettings $vmSettings
     * @param OperatingSystem $guestOs
     * @param VirtualDisks $disks
     * @param bool $modernEnvironment
     * @param bool $serialPortRequired
     * @param bool $hasNativeConfiguration
     */
    public function __construct(
        AgentSnapshot $agentSnapshot,
        string $name,
        VmHostProperties $vmHostProperties,
        AbstractVmSettings $vmSettings,
        OperatingSystem $guestOs,
        VirtualDisks $disks,
        bool $modernEnvironment,
        bool $serialPortRequired,
        bool $hasNativeConfiguration
    ) {
        $this->agentSnapshot = $agentSnapshot;
        $this->name = $name;
        $this->vmHostProperties = $vmHostProperties;
        $this->vmSettings = $vmSettings;
        $this->guestOs = $guestOs;
        $this->disks = $disks;
        $this->modernEnvironment = $modernEnvironment;
        $this->serialPortRequired = $serialPortRequired;
        $this->hasNativeConfiguration = $hasNativeConfiguration;
    }

    /**
     * @return string name of the virtual machine
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return VmHostProperties properties describing the VM Host
     */
    public function getVmHostProperties(): VmHostProperties
    {
        return $this->vmHostProperties;
    }

    public function getVmSettings(): AbstractVmSettings
    {
        return $this->vmSettings;
    }

    /**
     * @return OperatingSystem properties of the guest operating system
     */
    public function getGuestOs(): OperatingSystem
    {
        return $this->guestOs;
    }

    /**
     * @return VirtualDisks information about virtual machine disks
     */
    public function getDisks(): VirtualDisks
    {
        return $this->disks;
    }

    /**
     * @return int optional VNC port number
     */
    public function getVncPort(): int
    {
        return $this->vncPort;
    }

    /**
     * @return string optional VNC password
     */
    public function getVncPassword(): string
    {
        return $this->vncPassword;
    }

    /**
     * @return bool true if this VM supports VNC access
     */
    public function supportsVnc(): bool
    {
        return $this->vncPort !== 0;
    }

    /**
     * Set the optional VNC parameters
     *
     * @param int $vncPort
     * @param string $vncPassword
     */
    public function setVncParameters(int $vncPort, string $vncPassword)
    {
        $this->vncPort = $vncPort;
        $this->vncPassword = $vncPassword;
    }

    /**
     * @return bool virtual machine compatiblity
     */
    public function isModernEnvironment(): bool
    {
        return $this->modernEnvironment;
    }

    /**
     * @return bool true if the VM should have serial port configured (screenshot/verifications)
     */
    public function isSerialPortRequired(): bool
    {
        return $this->serialPortRequired;
    }

    /**
     * Whether to override VM configuration with one that was taken with backup.
     *
     * This is currently supported only for ESX where Agentless ESX VM backups
     * also store original .vmx configuration. So when this is set to true, the
     * configuration from that .vmx will be used to create restore VM.
     *
     * @return bool
     */
    public function hasNativeConfiguration(): bool
    {
        return $this->hasNativeConfiguration;
    }

    /**
     * Get AgentSnapshot corresponding to the restore.
     *
     * This can be used to get auxiliary configuration files used to configure
     * the virtual machine.
     *
     * @return AgentSnapshot
     */
    public function getAgentSnapshot(): AgentSnapshot
    {
        return $this->agentSnapshot;
    }
}
