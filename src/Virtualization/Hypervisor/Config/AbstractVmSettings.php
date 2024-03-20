<?php
namespace Datto\Virtualization\Hypervisor\Config;

use Datto\Config\JsonConfigRecord;
use Datto\Virtualization\Libvirt\Domain\NetworkMode;

/**
 * Base class for VmSettings
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
abstract class AbstractVmSettings extends JsonConfigRecord
{
    public const FORMAT_NATIVE = 0;
    public const FORMAT_LIBVIRT = 1;

    private int $cpuCount = 2;
    private int $ramMiB = 2048;
    private string $networkController = '';
    private string $storageController = '';
    private string $videoController = '';
    private string $networkMode = '';
    private string $macAddress = '';
    private bool $isUserDefined = false;
    protected array $libvirtStorageControllers = [
        'LSILogic'    => 'lsilogic',
        'PIIX3'       => 'piix3',
        'PIIX4'       => 'piix4',
        'ICH6'        => 'ich6',
        'LSILogicSAS' => 'lsisas1068',
        'IntelAhci'   => 'ich9-ahci',
        'BusLogic'    => 'buslogic',
        'ide'         => 'ide',
        'buslogic'    => 'buslogic',
        'lsilogic'    => 'lsilogic',
        'lsisas1068'  => 'lsisas1068',
        'vmpvscsi'    => 'vmpvscsi',
        'scsi'        => 'lsilogic',
        'auto'        => 'auto',
    ];

    public function __construct()
    {
        $this->loadDefaults();
    }

    /**
     * Get a hypervisor type the settings are for
     *
     * Primarily used to make key file extensions unique.
     */
    abstract protected function getType(): string;

    abstract protected function loadDefaults(): void;

    /**
     * The extension of the key file name, e.g. kvmSettings
     */
    public function getKeyName(): string
    {
        return sprintf('%sSettings', strtolower($this->getType()));
    }


    public function getCpuCount(): int
    {
        return $this->cpuCount;
    }

    public function setCpuCount(int $cpuCount): self
    {
        $this->cpuCount = $cpuCount;

        return $this;
    }

    public function getRam(): int
    {
        return $this->ramMiB;
    }

    public function setRam(int $ramMiB): self
    {
        $this->ramMiB = $ramMiB;

        return $this;
    }

    public function getNetworkController(): string
    {
        return $this->networkController;
    }

    public function setNetworkController(string $networkController): self
    {
        $this->networkController = $networkController;

        return $this;
    }

    /**
     * String representation of NetworkMode enum
     */
    public function getNetworkModeRaw(): string
    {
        return $this->networkMode;
    }

    public function getNetworkMode(): NetworkMode
    {
        if ($this->networkMode === NetworkMode::NAT()->value()) {
            return NetworkMode::NAT();
        } elseif ($this->networkMode === NetworkMode::INTERNAL()->value()) {
            return NetworkMode::INTERNAL();
        } elseif (preg_match('/^BRIDGE/i', $this->networkMode)) {
            return NetworkMode::BRIDGED();
        } else {
            return NetworkMode::NONE();
        }
    }

    public function setNetworkMode(string $networkMode): self
    {
        $this->networkMode = $networkMode;
        return $this;
    }

    public function getBridgeTarget(): string
    {
        $matches = [];
        if ($this->getNetworkMode() === NetworkMode::BRIDGED()
            && preg_match('/^bridge-(?<target>.*)/i', $this->networkMode, $matches)) {
            return $matches['target'];
        }

        return '';
    }

    public function getStorageController(int $format = self::FORMAT_NATIVE): string
    {
        if ($format == self::FORMAT_LIBVIRT) {
            return array_key_exists($this->storageController, $this->libvirtStorageControllers) ?
                $this->libvirtStorageControllers[$this->storageController] :
                $this->libvirtStorageControllers['LSILogicSAS'];
        } else {
            return $this->storageController;
        }
    }

    public function setStorageController(string $storageController): self
    {
        $this->storageController = $storageController;

        return $this;
    }

    public function getVideoController(): string
    {
        return $this->videoController;
    }

    public function setVideoController(string $videoController): self
    {
        $this->videoController = $videoController;

        return $this;
    }

    /**
     * If the settings were saved by the user and are not "defaults"
     *
     * This info is used by the libvirt XML builders on whether they can
     * adjust "defaults" based on the OS being virtualized on. However, if the
     * selection made by the user, it should not mess with it - they could have
     * installed proper drivers, for example.
     */
    public function isUserDefined(): bool
    {
        return $this->isUserDefined;
    }

    public function setUserDefined(bool $isUserDefined): self
    {
        $this->isUserDefined = $isUserDefined;

        return $this;
    }

    public function getMacAddress(): string
    {
        return $this->macAddress;
    }

    public function setMacAddress(string $macAddress): self
    {
        $this->macAddress = $macAddress;

        return $this;
    }

    public function isSata(): bool
    {
        return in_array(
            $this->getStorageController(),
            [
                'IntelAhci',
                'sata',
            ]
        );
    }

    public function isScsi(): bool
    {
        return in_array(
            $this->getStorageController(),
            [
                'LSILogic',
                'LSILogicSAS',
                'BusLogic',
                'buslogic',
                'lsilogic',
                'lsisas1068',
                'vmpvscsi',
                'scsi',
                'auto',
            ]
        );
    }

    public function isIde(): bool
    {
        return in_array(
            $this->getStorageController(),
            [
                'PIIX4',
                'PIIX3',
                'ICH6',
                'ide',
            ]
        );
    }

    public function load(array $vals): void
    {
        $this->loadDefaults();

        if (isset($vals['cpuCount'])) {
            $this->setCpuCount($vals['cpuCount']);
        }

        if (isset($vals['ram'])) {
            $this->setRam($vals['ram']);
        }

        if (isset($vals['networkController']) &&
            in_array($vals['networkController'], $this->getSupportedNetworkControllers())
        ) {
            $this->setNetworkController($vals['networkController']);
        }

        if (isset($vals['networkMode'])) {
            $this->setNetworkMode($vals['networkMode']);
        }

        if (isset($vals['storageController']) &&
            in_array($vals['storageController'], $this->getSupportedStorageControllers())
        ) {
            $this->setStorageController($vals['storageController']);
        }

        if (isset($vals['videoController']) &&
            in_array($vals['videoController'], $this->getSupportedVideoControllers())
        ) {
            $this->setVideoController($vals['videoController']);
        }

        if (isset($vals['userDefined'])) {
            $this->setUserDefined($vals['userDefined']);
        }

        if (isset($vals['macAddress'])) {
            $this->setMacAddress($vals['macAddress']);
        }
    }

    /**
     * Returns a list of supported network controller models.
     *
     * @return string[] A simple array with NIC models
     *   Empty array means setting is not user-configurable and hard-coded
     *   libvirt driver default is used.
     */
    public function getSupportedNetworkControllers(): array
    {
        return [];
    }

    /**
     * Returns a list of supported storage controller models.
     *
     * @return string[] A simple array with storage controllers
     *   Empty array means setting is not user-configurable and hard-coded
     *   libvirt driver default is used.
     */
    public function getSupportedStorageControllers(): array
    {
        return [];
    }

    /**
     * Returns a list of supported video controller types
     *
     * @return string[] A simple array with video controllers
     *   Empty array means setting is not user-configurable and hard-coded
     *   libvirt driver default is used.
     */
    public function getSupportedVideoControllers(): array
    {
        return [];
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'cpuCount' => $this->getCpuCount(),
            'ram' => $this->getRam(),
            'networkController' => $this->getNetworkController(),
            'networkMode' => $this->getNetworkModeRaw(),
            'storageController' => $this->getStorageController(),
            'videoController' => $this->getVideoController(),
            'userDefined' => $this->isUserDefined(),
            'macAddress' => $this->getMacAddress()
        ];
    }
}
