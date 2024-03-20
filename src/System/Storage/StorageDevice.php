<?php

namespace Datto\System\Storage;

use Datto\Utility\ByteUnit;
use JsonSerializable;

/**
 * This is a model class for a disk device.
 *
 * @author Matt Cheman <mcheman@datto.com>
 */
class StorageDevice implements JsonSerializable
{
    const STATUS_OS_DRIVE = 1;
    const STATUS_POOL = 2;
    const STATUS_AVAILABLE = 3;
    const STATUS_SPECIAL_DRIVE = 5;
    const STATUS_OPTICAL_DRIVE = 6;
    const STATUS_TRANSFER_DRIVE = 7;
    const STATUS_CACHE_DRIVE = 8;

    /** @var string */
    private $name;

    /** @var string */
    private $shortName;

    /** @var string */
    private $model;

    /** @var int */
    private $capacity;

    /** @var string */
    private $serial;

    /** @var int */
    private $status;

    /** @var bool */
    private $isVirtual;

    /** @var bool|null */
    private $isRotational;

    /** @var array */
    private $smartData;

    /** @var string */
    private $id;

    /** @var string[] */
    private $ids;

    /** @var int|null */
    private $scsiHostNumber;

    /** @var int|null */
    private $lunId;

    public function __construct(
        string $name,
        string $model,
        int $capacity,
        string $serial = null,
        int $status,
        bool $isVirtual,
        string $shortName,
        bool $isRotational = null,
        array $smartData = null,
        string $id = null,
        array $ids = [],
        int $scsiHostNumber = null,
        int $lunId = null
    ) {
        $this->name = $name;
        $this->model = $model;
        $this->capacity = $capacity;
        $this->serial = $serial;
        $this->status = $status;
        $this->isVirtual = $isVirtual;
        $this->isRotational = $isRotational;
        $this->smartData = $smartData;
        $this->id = $id;
        $this->ids = $ids;
        $this->shortName = $shortName;
        $this->scsiHostNumber = $scsiHostNumber;
        $this->lunId = $lunId;
    }

    /**
     * Get the name of this storage device
     *
     * @return string device name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the model of this storage device
     *
     * @return string device model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Get the numerical capacity of a device in bytes
     *
     * @return int device capacity in bytes
     */
    public function getCapacity()
    {
        return $this->capacity;
    }

    /**
     * Get the numerical capacity of a device in GB
     */
    public function getCapacityInGb(): float
    {
        return round(ByteUnit::BYTE()->toGiB($this->capacity));
    }

    /**
     * Returns the shortname of the device. e.g. sda
     *
     * @return string
     */
    public function getShortName() : string
    {
        return $this->shortName;
    }

    /**
     * Get the serial number of this storage device
     *
     * @return string the serial number of this storage device
     */
    public function getSerial()
    {
        return $this->serial;
    }

    /**
     * Get the status of this storage device
     *
     * @return int status of this storage device
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return bool
     */
    public function isVirtual()
    {
        return $this->isVirtual;
    }

    /**
     * @param bool $isVirtual
     */
    public function setIsVirtual($isVirtual)
    {
        $this->isVirtual = $isVirtual;
    }

    /**
     * @return bool|null
     */
    public function isRotational()
    {
        return $this->isRotational;
    }

    /**
     * @param bool|null $isRotational
     */
    public function setIsRotational($isRotational)
    {
        $this->isRotational = $isRotational;
    }

    /**
     * @return array
     */
    public function getSmartData()
    {
        return $this->smartData;
    }

    /**
     * @param array $smartData
     */
    public function setSmartData($smartData)
    {
        $this->smartData = $smartData;
    }

    /**
     * Since a device can have multiple ids, this returns the one used by zpool if it is part of a pool, otherwise
     * it returns the first id found.
     * @deprecated Please use getIds() instead
     * @return string|null
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * A device can have multiple ids, for example:
     *     'wwn-0x5001b444a4ddf84a' and 'ata-SanDisk_SD8SB8U1T001122_163076421533'
     * @return string[] All of the ids that can identify the device
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * @return int|null
     */
    public function getScsiHostNumber()
    {
        return $this->scsiHostNumber;
    }

    /**
     * @return int|null
     */
    public function getLunId()
    {
        return $this->lunId;
    }

    /**
     * Get a string representation of this storage device
     *
     * @return string device name, model and serial
     */
    public function toString()
    {
        $model = $this->getModel();
        $serial = $this->getSerial();
        $capacity = $this->getCapacity();

        $out = $this->name . " [{$model}";

        if (!$this->isVirtual) {
            $out .= " {$serial}";
        }

        $out .= sprintf('] (%s GB)', $this->getCapacityInGb());

        return $out;
    }

    /**
     * Get an array of the info of this device
     *
     * @return array
     */
    public function toArray() : array
    {
        return [
            'desc' => $this->toString(),
            'capacity' => $this->getCapacity(),
            'status' => $this->getStatus(),
            'serial' => $this->getSerial(),
            'model' => $this->getModel(),
            'name' => $this->getName(),
            'shortName' => $this->getShortName(),
            'id' => $this->getId(),
            'ids' => $this->getIds(),
            'smart' => $this->getSmartData(),
            'scsiHostNumber' => $this->getScsiHostNumber(),
            'lunId' => $this->getLunId()
        ];
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
