<?php

namespace Datto\Core\Drives;

/**
 * An abstraction for the physical drives that are behind the Dell BOSS
 * (Boot Optimized Server Storage) RAID controller.
 */
class BossPhysicalDrive extends AbstractDrive
{
    public const TYPE = 'boss-physical';

    /** @var array<string,string> */
    private array $pdInfo;
    /** @var array<array{id: int, name: string, current: int, worst: int, thresh: int, raw: int}> */
    private array $pdSmart;

    public function __construct(array $controllerSmartData, array $pdInfo, array $pdSmart)
    {
        parent::__construct($controllerSmartData);
        $this->pdInfo = $pdInfo;
        $this->pdSmart = $pdSmart;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getPath(): string
    {
        $id = $this->pdInfo['PD ID'] ?? '-1';
        return 'BOSS Port ' . $id;
    }

    public function getModel(): string
    {
        return $this->pdInfo['model'] ?? 'unknown';
    }

    public function getSerial(): string
    {
        return $this->pdInfo['Serial'] ?? 'unknown';
    }

    public function getFirmwareVersion(): string
    {
        return $this->pdInfo['Firmware version'] ?? '';
    }

    public function getId(): string
    {
        return 'ppid-' . $this->pdInfo['PPID'] ?? 'unknown';
    }

    public function getCapacityInBytes(): int
    {
        return intval($this->pdInfo['Size'] ?? 0) * 1024;
    }

    public function getTemperature(): int
    {
        $key = array_search('SSD Temperature', array_column($this->pdSmart, 'name'));
        if ($key) {
            return $this->pdSmart[$key]['current'];
        }
        return 0;
    }

    public function getPowerOnTime(): int
    {
        $key = array_search('Power-On Hours Count', array_column($this->pdSmart, 'name'));
        if ($key) {
            return $this->pdSmart[$key]['raw'];
        }
        return 0;
    }

    public function getPowerCycleCount(): int
    {
        $key = array_search('Power Cycle Count', array_column($this->pdSmart, 'name'));
        if ($key) {
            return $this->pdSmart[$key]['raw'];
        }
        return 0;
    }

    public function isSelfTestPassed(): bool
    {
        foreach ($this->getHealthAttributes() as $attribute) {
            // Count any attribute with a defined (and exceeded) manufacturer threshold as an error
            if ($attribute->isFailing()) {
                return false;
            }
        }
        return true;
    }

    public function isTrimSupported(): bool
    {
        // Trim is not supported on the physical drives, but can be performed on the controller (virtual drive)
        return false;
    }

    public function isRaidMember(): bool
    {
        return true;
    }

    public function getHealthAttributes(): array
    {
        $attributes = [];
        foreach ($this->pdSmart as $smartAttribute) {
            $attributes[] = new DriveHealthAttribute(
                $smartAttribute['name'],
                $smartAttribute['current'],
                $smartAttribute['id'],
                $smartAttribute['thresh'],
                $smartAttribute['raw']
            );
        }
        return $attributes;
    }
}
