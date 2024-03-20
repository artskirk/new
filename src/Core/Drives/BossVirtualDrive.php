<?php

namespace Datto\Core\Drives;

/**
 * Overrides a handful of methods for the BOSS (Boot Optimized Server Storage) device that
 * enumerates as a SATA drive, but is actually a hardware RAID. The controller does not provide
 * complete SMART information, so we have to use some data from the `mvcli` utility.
 */
class BossVirtualDrive extends AbstractDrive
{
    public const TYPE = 'boss-virtual';

    /** @var array<string, string> HBA info from mvcli */
    private array $hbaInfo;

    public function __construct(array $smartData, array $hbaInfo)
    {
        parent::__construct($smartData);
        $this->hbaInfo = $hbaInfo;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getFirmwareVersion(): string
    {
        return $this->hbaInfo['Firmware version'] ?? '';
    }

    public function getId(): string
    {
        $model_name = $this->smartData['model_name'] ?? null;
        $serial_number = $this->smartData['serial_number'] ?? null;

        if ($model_name && $serial_number) {
            // We use the standard ATA ID generation here.
            $id = sprintf('ata-%s_%s', str_replace(' ', '_', $model_name), $serial_number);
        } else {
            // Since we can't find any other identifying information just return the default ID
            $id = parent::getId();
        }
        return $id;
    }

    public function isRaidController(): bool
    {
        return true;
    }

    public function isSmartReliable(): bool
    {
        // BOSS cards sometimes report failed self-test despite physical drives reporting healthy. (BCDR-30053)
        return false;
    }

    public function getHealthAttributes(): array
    {
        // TODO: Health attribute reporting is held for a future ticket. We could look at the health
        // of the attached PDs, or possibly report some of the information from the VD/HBA here.
        return [];
    }
}
