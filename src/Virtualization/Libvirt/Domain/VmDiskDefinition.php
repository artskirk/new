<?php
namespace Datto\Virtualization\Libvirt\Domain;

use \ArrayObject;

/**
 * Represents Libvirt disk storage specification.
 *
 * When used as string it will return Libvirt compatible XML string.
 * {@link http://libvirt.org/formatdomain.html#elementsDisks}
 */
class VmDiskDefinition
{
    const TARGET_BUS_IDE = 'ide';
    const TARGET_BUS_SCSI = 'scsi';
    const TARGET_BUS_SATA = 'sata';
    const TARGET_BUS_VIRTIO = 'virtio';
    const TARGET_BUS_XEN = 'xen';
    const TARGET_BUS_USB = 'usb';
    const TARGET_BUS_SD = 'sd';
    const TARGET_BUS_AUTO = 'auto';

    const DISK_TYPE_FILE = 'file';
    const DISK_TYPE_BLOCK = 'block';
    const DISK_TYPE_DIR = 'dir';
    const DISK_TYPE_NETWORK = 'network';
    const DISK_TYPE_VOLUME = 'volume';

    const DISK_DEVICE_FLOPPY = 'floppy';
    const DISK_DEVICE_DISK = 'disk';
    const DISK_DEVICE_CDROM = 'cdrom';
    const DISK_DEVICE_LUN = 'lun';

    /**
     * @var string
     */
    protected $diskType;

    /**
     * @var string
     */
    protected $diskDevice;

    /**
     * @var string
     */
    protected $targetBus;

    /**
     * @var string
     */
    protected $targetDevice;

    /**
     * @var string
     */
    protected $sourcePathOrPool;

    /**
     * @var string
     */
    protected $sourceVolume;

    /**
     * @var ArrayObject|null
     */
    protected $address;

    /**
     * Gets the disk type.
     *
     * @return string
     *  One of the DISK_TYPE_* string constants.
     */
    public function getDiskType()
    {
        return $this->diskType;
    }

    /**
     * Sets the disk type.
     *
     * @param string $diskType
     *  One of the DISK_TYPE_* string constants.
     * @return self
     */
    public function setDiskType($diskType)
    {
        $this->diskType = $diskType;
        return $this;
    }

    /**
     * Gets the disk device type.
     *
     * @return string
     *  One of the DISK_DEVICE_* string constants.
     */
    public function getDiskDevice()
    {
        return $this->diskDevice;
    }

    /**
     * Sets the disk device type.
     *
     * @param string $diskDevice
     *  One of the DISK_DEVICE_* string constants.
     * @return self
     */
    public function setDiskDevice($diskDevice)
    {
        $this->diskDevice = $diskDevice;
        return $this;
    }

    /**
     * Gets the bus used by this virtual disk.
     *
     * @return string
     *  One of the TARGET_BUS_* string constants.
     */
    public function getTargetBus()
    {
        return $this->targetBus;
    }

    /**
     * Sets the bus used by this virtual disk.
     *
     * @param string $targetBus
     *  One of the TARGET_BUS_* string constants.
     * @return self
     */
    public function setTargetBus($targetBus)
    {
        $this->targetBus = $targetBus;
        return $this;
    }

    /**
     * Gets the target device name.
     *
     * The device name may be 'hda', 'hdb', 'sda', 'sdb' etc depending on the
     * target bus type. It's is not guaranteed to be the same as exposed in the
     * guest OS and should be treated merely as device ordering hint.
     *
     * @return string
     */
    public function getTargetDevice()
    {
        return $this->targetDevice;
    }

    /**
     * Sets the target device name.
     *
     * The device name may be 'hda', 'hdb', 'sda', 'sdb' etc depending on the
     * target bus type. It's is not guaranteed to be the same as exposed in the
     * guest OS and should be treated merely as device ordering hint.
     *
     * @param string $targetDevice
     * @return self
     */
    public function setTargetDevice($targetDevice)
    {
        $this->targetDevice = $targetDevice;
        return $this;
    }

    /**
     * Gets the disk source path or pool name.
     *
     * If the device type is DEVICE_TYPE_VOLUME, the path is the storage pool
     * name otherwise it is a file path.
     *
     * @return string
     */
    public function getSourcePath()
    {
        return $this->sourcePathOrPool;
    }

    /**
     * Gets the disk source path or pool name.
     *
     * If the device type is DEVICE_TYPE_VOLUME, the path is the storage pool
     * name otherwise it is a file path.
     *
     * @param string $sourcePathOrPool
     *  Either the file path or pool name if device type is DEVICE_TYPE_VOLUME
     * @return self
     */
    public function setSourcePath($sourcePathOrPool)
    {
        $this->sourcePathOrPool = $sourcePathOrPool;
        return $this;
    }

    /**
     * Gets the source volume name.
     *
     * Only used when the disk device is DISK_TYPE_VOLUME
     *
     * @return string
     *  The name of the volume to use for the disk source.
     */
    public function getSourceVolume()
    {
        return $this->sourceVolume;
    }

    /**
     * Gets the source volume name.
     *
     * Only used when the disk device is DISK_TYPE_VOLUME
     *
     * @param string $sourceVolume
     *  The name of the volme to use for the disk source.
     */
    public function setSourceVolume($sourceVolume)
    {
        $this->sourceVolume = $sourceVolume;
        return $this;
    }

    /**
     * Gets the <address> element of the disk device.
     *
     * An optional element that allows to attach it to specific <controller>
     * to further describe its properties.
     *
     * @return ArrayObject|null
     *  <code>
     *      $address = array(
     *          'type' => 'drive', // mandatory
     *          'controller' => 0, // optional, the controller index
     *          'bus' => 0, // optional
     *          'unit' => 0, // optional
     *  </code>
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Sets the <address> element of the disk device.
     *
     * An optional element that allows to attach it to specific <controller>
     * to further describe its properties.
     *
     * @param ArrayObject $address
     *  <code>
     *      $address = array(
     *          'type' => 'drive', // mandatory
     *          'controller' => 0, // optional, the controller index
     *          'bus' => 0, // optional
     *          'unit' => 0, // optional
     *  </code>
     * @return self
     */
    public function setAddress(ArrayObject $address)
    {
        $this->address = $address;
        return $this;
    }

    /**
     * Utility method to get a next free device name for a disk.
     *
     * @param ArrayObject<int, VmDiskDefinition> $disks
     *  List of already defined disks.
     * @param string $targetBusType
     *  A bus type to get device name for. One of TARGET_BUS_* string constants.
     * @return string
     *  hda, hdb, sda, sdb, vda, vdb etc.
     */
    public function getFreeDeviceName(ArrayObject $disks, $targetBusType = null)
    {
        $matchingDisks = array();
        $prefix = 'hd';

        if (null === $targetBusType) {
            $targetBusType = $this->getTargetBus();
        }

        switch ($targetBusType) {
            case self::TARGET_BUS_IDE:
                $prefix = 'hd';
                break;

            case self::TARGET_BUS_VIRTIO:
                $prefix = 'vd';
                break;

            case self::TARGET_BUS_SATA:
            case self::TARGET_BUS_SCSI:
                $prefix = 'sd';
                break;

            case self::TARGET_BUS_XEN:
                $prefix = 'xvd';
                break;

            case self::TARGET_BUS_USB:
                $prefix = 'ubd';
                break;

            case self::TARGET_BUS_SD:
                $prefix = 'fd';
                break;
        }

        foreach ($disks as $disk) {
            if ($disk->getTargetBus() === $targetBusType) {
                $matchingDisks[] = $disk;
            }
        }

        $nextIndex = count($matchingDisks);

        // returns next free letter from alphabet.
        return $prefix . chr(($nextIndex % 26) + 97);
    }

    /**
     * Outputs this object as XML definition.
     *
     * @return string
     *  Libvirt XML representation of this object.
     */
    public function __toString()
    {
        // create fake root to stop asXml from outputting xml declaration.
        $root = new \SimpleXmlElement('<root></root>');

        $disk = $root->addChild('disk');
        $disk->addAttribute('type', $this->getDiskType());
        $disk->addAttribute('device', $this->getDiskDevice());

        $source = $disk->addChild('source');

        if ($this->getDiskType() === self::DISK_TYPE_VOLUME) {
            $source->addAttribute('pool', $this->getSourcePath());
            $source->addAttribute('volume', $this->getSourceVolume());
        } elseif ($this->getDiskType() === self::DISK_TYPE_BLOCK) {
            $source->addAttribute('dev', $this->getSourcePath());
        } else {
            $source->addAttribute('file', $this->getSourcePath());
        }

        $target = $disk->addChild('target');
        $target->addAttribute('dev', $this->getTargetDevice());
        $target->addAttribute('bus', $this->getTargetBus());

        $addrSpec = $this->getAddress();
        if ($addrSpec) {
            $address = $disk->addChild('address');
            $address->addAttribute('type', $addrSpec['type']);

            if (isset($addrSpec['controller'])) {
                $address->addAttribute('controller', $addrSpec['controller']);
            }

            if (isset($addrSpec['bus'])) {
                $address->addAttribute('bus', $addrSpec['bus']);
            }

            if (isset($addrSpec['unit'])) {
                $address->addAttribute('unit', $addrSpec['unit']);
            }

            if ($addrSpec['type'] === 'pci') {
                $address->addAttribute('slot', $addrSpec['slot']);
                $address->addAttribute('function', $addrSpec['function']);

                if (isset($addrSpec['multifunction'])) {
                    $address->addAttribute('multifunction', $addrSpec['multifuncion']);
                }

                if (isset($addrSpec['domain'])) {
                    $address->addAttribute('domain', $addrSpec['domain']);
                }
            }
        }

        return $root->disk->asXml();
    }
}
