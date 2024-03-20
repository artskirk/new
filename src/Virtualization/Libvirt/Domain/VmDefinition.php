<?php

namespace Datto\Virtualization\Libvirt\Domain;

use Datto\Util\XmlUtil;
use SimpleXmlElement;
use \ArrayObject;

/**
 * Represents a generic VM (aka domain) libvirt XML definition.
 *
 * To get a complete domain XML, this class takes other *Definition classes
 * which define e.g. storage and networking aspects of the domain.
 *
 * {@link http://libvirt.org/formatdomain.html}
 */
class VmDefinition
{
    const TYPE_KVM = 'kvm';
    const TYPE_ESX = 'esx';

    const ARCH_32 = 'i686';
    const ARCH_64 = 'x86_64';

    const CPU_MODE_HOST_MODEL = 'host-model';
    const CPU_MODE_HOST_PASSTHROUGH = 'host-passthrough';
    const CPU_MODE_CUSTOM = 'custom';

    const CLOCK_OFFSET_LOCALTIME = 'localtime';

    protected string $type = self::TYPE_KVM;
    protected string $uuid = '';
    protected string $name = '';
    protected int $numCpu = 1;
    protected ?string $cpuMode = null;
    protected ?string $cpuModel = null;
    protected ?array $cpuFeatures = null;
    protected int $ramMib = 512;
    protected string $arch = self::ARCH_32;
    /** @var ArrayObject */
    protected ArrayObject $diskDevices;
    /** @var ArrayObject */
    protected ArrayObject $networkInterfaces;
    /** @var ArrayObject */
    protected ArrayObject $graphics;
    /** @var ArrayObject */
    protected ArrayObject $video;
    /** @var ArrayObject */
    protected ArrayObject $controllers;
    /** @var ArrayObject */
    protected ArrayObject $inputDevices;
    /** @var ArrayObject */
    protected ArrayObject $serialPorts;
    protected bool $hasAcpi = true;
    protected bool $hasApic = true;
    protected bool $hasPae = false;
    protected ?string $clockOffset = null;
    protected ?bool $suspendEnabled = null;
    protected ?bool $hibernateEnabled = null;

    public function __construct()
    {
        $this->diskDevices = new ArrayObject();
        $this->networkInterfaces = new ArrayObject();
        $this->graphics = new ArrayObject();
        $this->video = new ArrayObject();
        $this->controllers = new ArrayObject();
        $this->inputDevices = new ArrayObject();
        $this->serialPorts = new ArrayObject();
    }

    /**
     * Gets the domain type.
     *
     * @return string
     *  One of the TYPE_* string constants.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets the domain type.
     *
     * @TODO Add support for more VM types, hopefully all Libvirt can work with.
     *
     * @param string $type
     *  One of the TYPE_* string constants.
     * @return self
     */
    public function setType(string $type): VmDefinition
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get UUID
     *
     * @return string
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * Set UUID
     *
     * @param string $uuid
     *
     * @return self
     */
    public function setUuid(string $uuid): VmDefinition
    {
        $this->uuid = $uuid;

        return $this;
    }

    /**
     * Gets the human-readable domain name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the human-readable domain name.
     *
     * No spaces are allowed.
     *
     * @param string $name
     * @return self
     */
    public function setName(string $name): VmDefinition
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Gets the number of CPUs allocated to the domain.
     *
     * @return int
     */
    public function getNumCpu(): int
    {
        return $this->numCpu;
    }

    /**
     * Set the number of CPUs to allocate for the domain.
     *
     * Cannot exceed physical host CPU core count.
     *
     * @param int $numCpu
     * @return self
     */
    public function setNumCpu(int $numCpu): VmDefinition
    {
        $this->numCpu = $numCpu;
        return $this;
    }

    /**
     * getCpuMode
     *
     * @return string|null
     */
    public function getCpuMode(): ?string
    {
        return $this->cpuMode;
    }

    /**
     * Allows to set CPU mode.
     *
     * Whether to copy host CPU configuration.
     *
     * @param string $cpuMode
     *  host-model, host-passthrough or null to use default.
     *
     * @return self
     */
    public function setCpuMode(string $cpuMode): VmDefinition
    {
        $this->cpuMode = $cpuMode;
        return $this;
    }

    /**
     * Get CPU model.
     *
     * @return string|null
     *  The emulated CPU model, see /usr/share/libvirt/cpu_map.xml for possible
     *  values. Null if not set.
     */
    public function getCpuModel(): ?string
    {
        return $this->cpuModel;
    }

    /**
     * Set the emulated CPU model.
     *
     * Do not mix with setCpuMode.
     *
     * @param string $cpuModel
     *  The emulated CPU model, see /usr/share/libvirt/cpu_map.xml for possible
     *  values or null to use defualt.
     *
     * @return self
     */
    public function setCpuModel(string $cpuModel): VmDefinition
    {
        $this->cpuModel = $cpuModel;
        return $this;
    }

    /**
     * Get CPU feature settings for QEMU/KVM.
     *
     * QEMU/KVM allows to fine-tune exposed CPU feature set to the guest OS.
     * Using this one can enable emulation of missing CPU feature or hide it,
     * e.g:
     * <feature name='vmx' policy='require' />
     * <feature name='hypervisor' policy='disable' />
     *
     * exposes AMD-V/VT-x to guest while hiding the fact that it's being
     * actually virtualized
     *
     * @return array|null
     *  <code>
     *      array(
     *          'vmx' => true,
     *          'hypervisor' => false
     *      );
     *  </code>
     */
    public function getCpuFeatures(): ?array
    {
        return $this->cpuFeatures;
    }

    /**
     * Set the CPU features for QEMU/KVM.
     *
     *
     * QEMU/KVM allows to fine-tune exposed CPU feature set to the guest OS.
     * Using this one can enable emulation of missing CPU feature or hide it,
     * e.g:
     * <feature name='vmx' policy='require' />
     * <feature name='hypervisor' policy='disable' />
     *
     * exposes AMD-V/VT-x to guest while hiding the fact that it's being
     * actually virtualized
     *
     * @param array $features
     *  <code>
     *      array(
     *          'vmx' => true,
     *          'hypervisor' => false
     *      );
     *  </code>
     *
     * @return self
     */
    public function setCpuFeatures(array $features): VmDefinition
    {
        $this->cpuFeatures = $features;

        return $this;
    }

    /**
     * Gets the architecture used for the domain.
     *
     * @return string
     *  One of the ARCH_* string constants.
     */
    public function getArch(): string
    {
        return $this->arch;
    }

    /**
     * Sets the architecture used for domain.
     *
     * @param string $arch
     *  One of the ARCH_* string constants.
     * @return self
     */
    public function setArch(string $arch): VmDefinition
    {
        $this->arch = $arch;
        return $this;
    }

    /**
     * Gets the amount of RAM allocated to the domain.
     *
     * @return int
     *  RAM in MiB (aka 1024 multiplier)
     */
    public function getRamMib(): int
    {
        return $this->ramMib;
    }

    /**
     * Sets the amount of RAM allocated to domain.
     *
     * @param int $ramMib
     *  RAM in MiB (aka 1024 multiplier)
     * @return self
     */
    public function setRamMib(int $ramMib): VmDefinition
    {
        $this->ramMib = $ramMib;
        return $this;
    }

    /**
     * Gets the disk devices defined in the domain.
     *
     * @return ArrayObject
     */
    public function getDiskDevices(): ArrayObject
    {
        return $this->diskDevices;
    }

    /**
     * Sets the disk devices for the domain.
     *
     * @param ArrayObject $diskDevices
     * @return self
     */
    public function setDiskDevices(ArrayObject $diskDevices): VmDefinition
    {
        $this->diskDevices = $diskDevices;
        return $this;
    }

    /**
     * Gets the controller devices defined in the domain.
     *
     * @return ArrayObject
     */
    public function getControllers(): ArrayObject
    {
        return $this->controllers;
    }

    /**
     * Sets the controller devices defined in the domain.
     *
     * @param ArrayObject $controllers
     * @return self
     */
    public function setControllers(ArrayObject $controllers): VmDefinition
    {
        $this->controllers = $controllers;
        return $this;
    }

    /**
     * Gets the input devices defined in the domain.
     *
     * @return ArrayObject
     */
    public function getInputDevices(): ArrayObject
    {
        return $this->inputDevices;
    }

    /**
     * Sets the input devices defined in the domain.
     *
     * @param ArrayObject $inputDevices
     * @return self
     */
    public function setInputDevices(ArrayObject $inputDevices): VmDefinition
    {
        $this->inputDevices = $inputDevices;
        return $this;
    }

    /**
     * Get the network interfaces defined in the domain.
     *
     * @return ArrayObject
     */
    public function getNetworkInterfaces(): ArrayObject
    {
        return $this->networkInterfaces;
    }

    /**
     * Sets the network interfaces for the domain.
     *
     * @param ArrayObject $networkInterfaces
     * @return self
     */
    public function setNetworkInterfaces(ArrayObject $networkInterfaces): VmDefinition
    {
        $this->networkInterfaces = $networkInterfaces;
        return $this;
    }

    /**
     * Gets the graphics adapters defined in the domain.
     *
     * @return ArrayObject
     */
    public function getGraphicsAdapters(): ArrayObject
    {
        return $this->graphics;
    }

    /**
     * Sets the graphics adapters used by the domain.
     *
     * @param ArrayObject $graphics
     * @return self
     */
    public function setGraphicsAdapters(ArrayObject $graphics): VmDefinition
    {
        $this->graphics = $graphics;
        return $this;
    }

    /**
     * Gets the virtual video card devices.
     *
     * @return ArrayObject
     */
    public function getVideo(): ArrayObject
    {
        return $this->video;
    }

    /**
     * Sets the virtual video card devices.
     *
     * @param ArrayObject $video
     * @return self
     */
    public function setVideo(ArrayObject $video): VmDefinition
    {
        $this->video = $video;
        return $this;
    }

    /**
     * Gets the serial port devices
     *
     * @return ArrayObject
     */
    public function getSerialPorts(): ArrayObject
    {
        return $this->serialPorts;
    }

    /**
     * Sets the serial port devices
     *
     * @param ArrayObject<int, VmSerialPortDefinition> $serialPorts
     * @return self
     */
    public function setSerialPorts(ArrayObject $serialPorts): VmDefinition
    {
        $this->serialPorts = $serialPorts;
        return $this;
    }

    /**
     * Whether the domain supports ACPI.
     *
     * @return bool
     */
    public function hasAcpi(): bool
    {
        return $this->hasAcpi;
    }

    /**
     * Sets whether the domain supports ACPI.
     *
     * @param bool $hasAcpi
     * @return self
     */
    public function setHasAcpi(bool $hasAcpi): VmDefinition
    {
        $this->hasAcpi = $hasAcpi;
        return $this;
    }

    /**
     * Whether the domain supports APIC
     *
     * @return bool
     */
    public function hasApic(): bool
    {
        return $this->hasApic;
    }

    /**
     * Sets whether the domain supports APIC
     *
     * @param bool $hasApic
     * @return self
     */
    public function setHasApic(bool $hasApic): VmDefinition
    {
        $this->hasApic =  $hasApic;
        return $this;
    }

    /**
     * Whether the domain has PAE enabled.
     *
     * @return bool
     */
    public function hasPae(): bool
    {
        return $this->hasPae;
    }

    /**
     * Sets whether to enable PAE for the domain.
     *
     * @param bool $hasPae
     * @return self
     */
    public function setHasPae(bool $hasPae): VmDefinition
    {
        $this->hasPae = $hasPae;
        return $this;
    }

    /**
     * Sets the domain's clock offset.
     *
     * @param string $clockOffset Example: 'localtime'
     */
    public function setClockOffset(string $clockOffset)
    {
        $this->clockOffset = $clockOffset;
    }

    /**
     * @return ?string the domain's clock offset.
     */
    public function getClockOffset(): ?string
    {
        return $this->clockOffset;
    }

    /**
     * Checks if suspend support is enabled.
     *
     * @return bool|null NULL if "hypervisor-default" is used
     */
    public function isSuspendEnabled(): ?bool
    {
        return $this->suspendEnabled;
    }

    /**
     * Sets whether to enable suspend support for VM
     *
     * @param bool|null $enabled NULL to leave this up to hypervisor defaults.
     */
    public function setSuspendEnabled(?bool $enabled)
    {
        $this->suspendEnabled = $enabled;
    }

    /**
     * Checks if hibernate support is enabled.
     *
     * @return bool|null NULL if "hypervisor-default" is used
     */
    public function isHibernateEnabled(): ?bool
    {
        return $this->hibernateEnabled;
    }

    /**
     * Sets whether to enable hibernate support for VM
     *
     * @param bool|null $enabled NULL to leave this up to hypervisor defaults.
     */
    public function setHibernateEnabled(?bool $enabled)
    {
        $this->hibernateEnabled = $enabled;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        $domain = new SimpleXmlElement('<domain></domain>');
        $type = $this->getType();

        // libvirt refers to ESX as VMWare internally.
        if ($type === 'esx') {
            $type = 'vmware';
        }

        $domain->addAttribute('type', $type);

        $uuid = $this->getUuid();

        if (!empty($uuid)) {
            $domain->uuid = $uuid;
        }

        // set domain name
        $domain->name = $this->getName();

        // set domain memory
        $memory = $domain->addChild('memory');
        $memory->addAttribute('unit', 'MiB');
        $domain->memory = $this->getRamMib();

        // domain CPU cores/sockets
        $domain->vcpu = $this->getNumCpu();

        // domain OS config
        $os = $domain->addChild('os');
        $os->type = 'hvm';
        $os->type['arch'] = $this->getArch();

        $bootCd = $os->addChild('boot');
        $bootCd->addAttribute('dev', 'cdrom');
        $bootHd = $os->addChild('boot');
        $bootHd->addAttribute('dev', 'hd');

        $features = $domain->addChild('features');

        if ($this->hasAcpi()) {
            $features->addChild('acpi');
        }

        if ($this->hasApic()) {
            $features->addChild('apic');
        }

        if ($this->hasPae()) {
            $features->addChild('pae');
        }

        // set domain CPU settings
        $cpuSettings = $domain->addChild('cpu');
        $topology = $cpuSettings->addChild('topology');
        $topology->addAttribute('sockets', '1');
        $topology->addAttribute('cores', (string) $this->getNumCpu());
        $topology->addAttribute('threads', '1');
        $topology->addAttribute('dies', '1');

        $cpuMode = $this->getCpuMode();
        if ($cpuMode) {
            $cpuSettings->addAttribute('mode', $cpuMode);
        }

        $cpuModel = $this->getCpuModel();
        if ($cpuModel) {
            $cpuSettings->addChild('model', $cpuModel);
        }

        $cpuFeatures = $this->getCpuFeatures();
        if ($cpuFeatures) {
            foreach ($cpuFeatures as $name => $enable) {
                $feature = $cpuSettings->addChild('feature');
                $feature->addAttribute('name', $name);
                $feature->addAttribute('policy', $enable ? 'require' : 'disable');
            }
        }

        // set clock offset
        if ($this->getClockOffset()) {
            $clockSettings = $domain->addChild('clock');
            $clockSettings->addAttribute('offset', $this->getClockOffset());
        }

        // set power management rules if defined
        $pm = null;
        if ($this->suspendEnabled !== null) {
            $pm = $domain->addChild('pm');
            $suspend = $pm->addChild('suspend-to-mem');
            $suspend->addAttribute('enabled', $this->suspendEnabled ? 'yes' : 'no');
        }

        if ($this->hibernateEnabled !== null) {
            if (!$pm) {
                $pm = $domain->addChild('pm');
            }

            $hibernate = $pm->addChild('suspend-to-disk');
            $hibernate->addAttribute('enabled', $this->hibernateEnabled ? 'yes' : 'no');
        }

        $devices = $domain->addChild('devices');

        foreach ($this->getDiskDevices() as $diskDevice) {
            $disk = new SimpleXmlElement((string)$diskDevice);
            XmlUtil::addChildXml($devices, $disk);
        }

        foreach ($this->getNetworkInterfaces() as $networkInterface) {
            $nic = new SimpleXmlElement((string)$networkInterface);
            XmlUtil::addChildXml($devices, $nic);
        }

        foreach ($this->getControllers() as $controllerDevice) {
            $controller = new SimpleXmlElement((string)$controllerDevice);
            XmlUtil::addChildXml($devices, $controller);
        }

        foreach ($this->getGraphicsAdapters() as $graphicsAdapter) {
            $graphics = new SimpleXmlElement((string)$graphicsAdapter);
            XmlUtil::addChildXml($devices, $graphics);
        }

        foreach ($this->getVideo() as $videoCard) {
            $video = new SimpleXmlElement((string)$videoCard);
            XmlUtil::addChildXml($devices, $video);
        }

        foreach ($this->getInputDevices() as $inputDevice) {
            $input = new SimpleXmlElement((string)$inputDevice);
            XmlUtil::addChildXml($devices, $input);
        }

        foreach ($this->getSerialPorts() as $serialPort) {
            $serial = new SimpleXmlElement((string)$serialPort);
            XmlUtil::addChildXml($devices, $serial);
        }

        $xml = $domain->asXML();

        return $xml;
    }
}
