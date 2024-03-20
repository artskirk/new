<?php

namespace Datto\Virtualization\Libvirt\Builders;

use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Utility\ByteUnit;
use Datto\Virtualization\Libvirt\Domain\VmControllerDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDefinition;
use Datto\Virtualization\Libvirt\Domain\VmDiskDefinition;
use Datto\Virtualization\Libvirt\Domain\VmNetworkDefinition;
use Datto\Virtualization\Libvirt\Domain\VmVideoDefinition;
use ArrayObject;
use RuntimeException;

/**
 * Build VmDiskDefinition from the content of a ".vmx" data.
 *
 * @author Dawid Zamirski <dzamirsk@datto.com>
 */
class EsxVmxMachineBuilder extends BaseVmDefinitionBuilder
{
    const ALPHABET_SIZE = 26;
    const ASCII_ALPHABET_START = 97;

    /**
     * {@inheritdoc}
     */
    public function build(VmDefinition $vmDefinition)
    {
        $vmxData = $this->parseVmxContent();

        $this->processBasicSettings($vmxData, $vmDefinition);
        $this->processScsiControllersAndDisks($vmxData, $vmDefinition);
        $this->processIdeDisks($vmxData, $vmDefinition);
        $this->processFloppyDisks($vmxData, $vmDefinition);
        $this->processNetworkInterfaces($vmxData, $vmDefinition);
        $this->processVideoDevices($vmxData, $vmDefinition);
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processBasicSettings(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        // if ends with 64, it's 64bit
        $arch = substr($vmxData['guestOS'] ?? 'Other-64', -2) === '64'
                ? VmDefinition::ARCH_64
                : VmDefinition::ARCH_32;

        // defaults taken from:
        // https://github.com/libvirt/libvirt/blob/master/src/vmx/vmx.c#L42
        $vmDefinition
            ->setType(VmDefinition::TYPE_ESX)
            ->setName($this->getContext()->getName())
            ->setNumCpu($vmxData['numvcpus'] ?? 1)
            ->setRamMib($vmxData['memSize'] ?? 32)
            ->setArch($arch)
        ;
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processScsiControllersAndDisks(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        $controllers = $vmDefinition->getControllers();

        // there are max of 4 scsi controllers, 16 disks (units) on each
        for ($i = 0; $i < 4; $i++) {
            // e.g. scsi0.present = TRUE
            $prefix = sprintf('scsi%d', $i);
            if (!($vmxData["$prefix.present"] ?? false)) {
                continue;
            }

            $controller = new VmControllerDefinition();
            $controller
                ->setType(VmControllerDefinition::TYPE_SCSI)
                ->setIndex($i)
                ->setModel($vmxData["$prefix.virtualDev"])
            ;

            $controllers->append($controller);

            $this->addScsiDisks($vmxData, $i, $vmDefinition);
        }
    }

    /**
     * @param array $vmxData
     * @param int $controllerIndex
     */
    private function addScsiDisks(array $vmxData, int $controllerIndex, VmDefinition $vmDefinition)
    {
        $disks = $vmDefinition->getDiskDevices();

        // each SCSI controller may have up to 16 drives (units), with 7th not
        // available as it's reserved
        for ($unit = 0; $unit < 16; $unit++) {
            // e.g scsi0:0.present = TRUE
            $prefix = sprintf('scsi%d:%d', $controllerIndex, $unit);
            if (!($vmxData["$prefix.present"] ?? false)) {
                continue;
            }

            switch ($vmxData["$prefix.deviceType"]) {
                case 'scsi-hardDisk':
                    $deviceType = VmDiskDefinition::DISK_DEVICE_DISK;
                    $diskType = VmDiskDefinition::DISK_TYPE_FILE;

                    break;

                case 'cdrom-image':
                    $deviceType = VmDiskDefinition::DISK_DEVICE_CDROM;
                    $diskType = VmDiskDefinition::DISK_TYPE_FILE;

                    break;

                case 'atapi-cdrom':
                    $deviceType = VmDiskDefinition::DISK_DEVICE_CDROM;
                    $diskType = VmDiskDefinition::DISK_TYPE_BLOCK;

                    break;

                default:
                    throw new RuntimeException(sprintf(
                        'Unsupported VMX deviceType: %s',
                        $vmxData["$prefix.deviceType"]
                    ));
            }

            $disk = new VmDiskDefinition();
            $disk
                ->setDiskDevice($deviceType)
                ->setDiskType($diskType)
                ->setSourcePath($vmxData["$prefix.fileName"])
                ->setTargetBus(VmDiskDefinition::TARGET_BUS_SCSI)
                ->setTargetDevice($this->getBlockDeviceName(
                    'sd',
                    $controllerIndex * 15 + $unit // 15 because 7th index is reserved and not availble to users
                ))
                ->setAddress(new ArrayObject([
                    'type' => 'drive',
                    'controller' => $controllerIndex,
                    'unit' => $unit,
                ]))
            ;

            $disks->append($disk);
        }
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processIdeDisks(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        $disks = $vmDefinition->getDiskDevices();
        // There's always 1 IDE controler present (no need to create one
        // explicitly) with 2 buses with 2 units on each
        for ($busIndex = 0; $busIndex < 2; $busIndex++) {
            for ($unit = 0; $unit < 2; $unit++) {
                $prefix = sprintf('ide%d:%d', $busIndex, $unit);
                if (!($vmxData["$prefix.present"] ?? false)) {
                    continue;
                }

                switch ($vmxData["$prefix.deviceType"] ?? 'ata-hardDisk') {
                    case 'ata-hardDisk':
                        $deviceType = VmDiskDefinition::DISK_DEVICE_DISK;
                        $diskType = VmDiskDefinition::DISK_TYPE_FILE;

                        break;

                    case 'cdrom-image':
                        $deviceType = VmDiskDefinition::DISK_DEVICE_CDROM;
                        $diskType = VmDiskDefinition::DISK_TYPE_FILE;

                        break;

                    case 'atapi-cdrom':
                        $deviceType = VmDiskDefinition::DISK_DEVICE_CDROM;
                        $diskType = VmDiskDefinition::DISK_TYPE_BLOCK;

                        break;

                    default:
                        throw new RuntimeException(sprintf(
                            'Unsupported VMX deviceType: %s',
                            $vmxData["$prefix.deviceType"]
                        ));
                }

                $disk = new VmDiskDefinition();
                $disk
                    ->setDiskDevice($deviceType)
                    ->setDiskType($diskType)
                    ->setSourcePath($vmxData["$prefix.fileName"])
                    ->setTargetBus(VmDiskDefinition::TARGET_BUS_IDE)
                    ->setTargetDevice($this->getBlockDeviceName(
                        'hd',
                        $busIndex * 2 + $unit
                    ))
                    ->setAddress(new ArrayObject([
                        'type' => 'drive',
                        'controller' => 0,
                        'bus' => $busIndex,
                        'unit' => $unit
                    ]))
                ;

                $disks->append($disk);
            }
        }
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processFloppyDisks(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        $disks = $vmDefinition->getDiskDevices();
        for ($i = 0; $i < 2; $i++) {
            $prefix = sprintf('floppy%d', $i);
            if (!($vmxData["$prefix.present"] ?? false)) {
                continue;
            }

            $diskType = $vmxData["$prefix.fileType"] === 'file'
                        ? VmDiskDefinition::DISK_TYPE_FILE
                        : VmDiskDefinition::DISK_TYPE_BLOCK;

            $disk = new VmDiskDefinition();
            $disk
                ->setDiskDevice(VmDiskDefinition::DISK_DEVICE_FLOPPY)
                ->setDiskType($diskType)
                ->setSourcePath($vmxData["$prefix.fileName"])
                ->setTargetBus(VmDiskDefinition::TARGET_BUS_IDE)
                ->setTargetDevice($this->getBlockDeviceName('fd', $i)) // 1 controller and 1 disk
                ->setAddress(new ArrayObject([
                    'type' => 'drive',
                    'controller' => $i,
                    'unit' => 0,
                ]))
            ;

            $disks->append($disk);
        }
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processNetworkInterfaces(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        $networks = $vmDefinition->getNetworkInterfaces();
        $maxNets = 0;

        foreach ($vmxData as $key => $val) {
            if (preg_match('/ethernet\d+\.present.*/', $key)) {
                $maxNets++;
            }
        }

        for ($i = 0; $i < $maxNets; $i++) {
            $prefix = sprintf('ethernet%d', $i);
            if (!($vmxData["$prefix.present"] ?? false)) {
                continue;
            }

            $linkState = ($vmxData["$prefix.startConnected"] ?? true)
                         ? VmNetworkDefinition::LINK_STATE_UP
                         : VmNetworkDefinition::LINK_STATE_DOWN;

            $network = new VmNetworkDefinition();
            $network
                ->setInterfaceModel($vmxData["$prefix.virtualDev"])
                ->setInterfaceType(VmNetworkDefinition::INTERFACE_TYPE_BRIDGE)
                ->setSourceBridge($vmxData["$prefix.networkName"])
                ->setLinkState($linkState)
            ;

            $networks->append($network);
        }
    }

    /**
     * @param array $vmxData
     * @param VmDefinition $vmDefinition
     */
    private function processVideoDevices(
        array $vmxData,
        VmDefinition $vmDefinition
    ) {
        if (isset($vmxData['svga.vramSize'])) {
            $videos = $vmDefinition->getVideo();

            $video = new VmVideoDefinition();
            $video
                ->setModel(VmVideoDefinition::MODEL_VMWARE_VGA)
                ->setVramKib(ByteUnit::BYTE()->toKiB($vmxData['svga.vramSize']))
            ;

            $videos->append($video);
        }
    }

    /**
     * Converts raw VMX content to an associative array.
     *
     * The resulting array has keys as "vmx" fields and values are the values.
     *
     * @return array
     */
    private function parseVmxContent(): array
    {
        $agentSnapshot = $this->getContext()->getAgentSnapshot();
        $vmxContent = $agentSnapshot->getKey(AgentSnapshotRepository::KEY_VMX_FILE_NAME);

        if (!$vmxContent) {
            throw new RuntimeException('Failed to retrieve content of the VMX file');
        }

        // Unquote boolean strings to make them actual booleans
        $vmxContent = str_ireplace(
            ['"TRUE"', '"FALSE"'],
            ['true', 'false'],
            $vmxContent
        );

        $result = parse_ini_string($vmxContent, false, INI_SCANNER_TYPED);

        if (!$result) {
            throw new RuntimeException('Failed to parse content of the VMX file');
        }

        return $result;
    }

    /**
     * Get the block device name based on the unit index within storage controller
     *
     * This is used to create device names like 'sda', 'sdb', 'hda', 'hdb'
     * based on the disk unit number in the storage controller. If the index
     * ends up being bigger than there's letters in the alphabet, it will
     * prefix the letter and wrap around, resulting in e.g. 'sdaa', 'sdab',
     * 'sdac', 'sdba' etc
     *
     * @param string $prefix
     * @param int $index
     *
     * @return string
     */
    private function getBlockDeviceName(string $prefix, int $index): string
    {
        $character = $index;

        // find how many characters are needed to handle the index - if the
        // index would get past 'z' we need to prefix 'a' and reset so we get
        // 'aa', 'ab' .. 'az', 'ba'.. 'bz', 'ca' and so on
        for ($charCount = 0; $character >= 0; $charCount++) {
            $character = (int) ($character / self::ALPHABET_SIZE) - 1;
        }

        $offset = 0;
        $character = $index;

        // initialize so it has final length and can be used as array while
        // remains as string type, not needed in PHP 7.1+ where it can simply be
        // initialized with '';
        $letters = str_repeat(' ', $charCount);

        // now walk backward from least significant letter, converting index to
        // ASCII char code.
        for ($i = $charCount - 1; $character >= 0; $i--) {
            $letters[$offset + $i] = chr(self::ASCII_ALPHABET_START + ($character % self::ALPHABET_SIZE));
            $character = (int) ($character / self::ALPHABET_SIZE) - 1;
        }

        return $prefix . $letters;
    }
}
