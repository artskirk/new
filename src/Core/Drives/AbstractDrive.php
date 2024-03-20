<?php

namespace Datto\Core\Drives;

use JsonSerializable;

/**
 * An abstraction of a Storage Drive, intended to be mostly generic to cover as many types of
 * drives as we are likely to see. Since the vast majority of drives report information in a
 * relatively standardized JSON format to smartctl, we include the "default" parsing that works
 * for most drives here, to prevent re-implementing this logic in every class.
 */
abstract class AbstractDrive implements JsonSerializable
{
    /** @var array JSON Data as reported by smartctl */
    protected array $smartData;
    /** @var DriveError[] An array of errors detected on this drive */
    private array $errors;

    public function __construct(array $smartData)
    {
        $this->smartData = $smartData;
        $this->errors = [];
    }

    /**
     * @return string Determine the type of drive (SSD, HDD, NVMe, SAS, etc...)
     */
    abstract public function getType(): string;

    /**
     * @return string The device path (e.g. /dev/sda), or an empty string
     */
    public function getPath(): string
    {
        return $this->smartData['device']['name'] ?? '';
    }

    /**
     * @return string The drive model information, which may include the vendor name
     */
    public function getModel(): string
    {
        return $this->smartData['model_name'] ?? 'unknown';
    }

    /**
     * @return string The drive serial number
     */
    public function getSerial(): string
    {
        return $this->smartData['serial_number'] ?? 'unknown';
    }

    /**
     * @return string The drive firmware version
     */
    public function getFirmwareVersion(): string
    {
        return $this->smartData['firmware_version'] ?? '';
    }

    /**
     * @return string The drive identifier, generally a WWN or similar
     */
    public function getId(): string
    {
        // As a fallback, in case a drive doesn't have any other identifying characteristics,
        // just return the final component of the dev path (e.g. `sda` for /dev/sda).
        $path = $this->getPath();
        return substr($path, intval(strrpos($path, '/')) + 1);
    }

    /**
     * @return int The drive size, in bytes. 0 if unknown.
     */
    public function getCapacityInBytes(): int
    {
        return $this->smartData['user_capacity']['bytes'] ?? $this->smartData['nvme_total_capacity'] ?? 0;
    }

    /**
     * @return int The current temperature of the drive, in Degrees C. 0 if unknown.
     */
    public function getTemperature(): int
    {
        return $this->smartData['temperature']['current'] ?? 0;
    }

    /**
     * @return int The power-on time for the drive, in hours. 0 if unknown.
     */
    public function getPowerOnTime(): int
    {
        return $this->smartData['power_on_time']['hours'] ?? 0;
    }

    /**
     * @return int The number of times a drive has been power cycled. 0 if unknown.
     */
    public function getPowerCycleCount(): int
    {
        return $this->smartData['power_cycle_count'] ?? 0;
    }

    /**
     * @return bool Whether the drive's internal self-test has passed
     */
    public function isSelfTestPassed(): bool
    {
        return $this->smartData['smart_status']['passed'] ?? false;
    }

    /**
     * @return int The number of errors reported by this drive. For detailed information refer to the
     * DriveHealthAttributes
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    /**
     * @return bool Whether TRIM is supported on this drive
     */
    public function isTrimSupported(): bool
    {
        return $this->smartData['trim']['supported'] ?? false;
    }

    /**
     * @return bool Whether this drive is a rotational drive, with spinning platters
     */
    public function isRotational(): bool
    {
        return $this->getRotationRate() !== 0;
    }

    /**
     * @return int The rate of rotation of the drive, in RPM. 0 for Solid-state drives.
     */
    public function getRotationRate(): int
    {
        return $this->smartData['rotation_rate'] ?? 0;
    }

    /**
     * @return bool Whether this drive is a virtual drive exposed by a hardware RAID controller
     */
    public function isRaidController(): bool
    {
        return false;
    }

    /**
     * @return bool Whether this drive is a physical drive behind a hardware RAID controller
     */
    public function isRaidMember(): bool
    {
        return false;
    }

    /**
     * @return int The epoch timestamp of the time the drive status was refreshed
     */
    public function getLastSeenEpoch(): int
    {
        return $this->smartData['local_time']['time_t'] ?? 0;
    }

    /**
     * @return DriveHealthAttribute[] An array of detailed drive health attributes
     */
    public function getHealthAttributes(): array
    {
        return [];
    }

    /**
     * @return DriveError[] An array of drive errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Adds an error to this drive, which will be serialized with the rest of the information
     *
     * @param DriveError $error The error to add
     */
    public function addError(DriveError $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * @return bool Whether reported SMART issues should be taken into account or ignored
     */
    public function isSmartReliable(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->getType(),
            'path' => $this->getPath(),
            'model' => $this->getModel(),
            'serial' => $this->getSerial(),
            'firmware' => $this->getFirmwareVersion(),
            'id' => $this->getId(),
            'capacity' => $this->getCapacityInBytes(),
            'tempInC' => $this->getTemperature(),
            'powerOnHours' => $this->getPowerOnTime(),
            'powerCycleCount' => $this->getPowerCycleCount(),
            'selfTestPassed' => $this->isSelfTestPassed(),
            'errorCount' => $this->getErrorCount(),
            'trimSupported' => $this->isTrimSupported(),
            'rotational' => $this->isRotational(),
            'rotationRate' => $this->getRotationRate(),
            'raidController' => $this->isRaidController(),
            'raidMember' => $this->isRaidMember(),
            'lastSeen' => $this->getLastSeenEpoch(),
            'attributes' => $this->getHealthAttributes(),
            'errors' => $this->getErrors(),
            'smartReliable' => $this->isSmartReliable(),
        ];
    }
}
