<?php

namespace Datto\Core\Drives;

/**
 * A drive abstraction for a SAS (Serially-attached SCSI) drive, commonly found in enterprise systems.
 */
class SasDrive extends AbstractDrive
{
    public const TYPE = 'sas';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getFirmwareVersion(): string
    {
        return $this->smartData['scsi_revision'] ?? parent::getFirmwareVersion();
    }

    public function getId(): string
    {
        $unitId = $this->smartData['logical_unit_id'] ?? null;
        if ($unitId) {
            return 'wwn-' . $unitId;
        }
        return parent::getId();
    }

    public function getModel(): string
    {
        // Smartctl reports SAS model names in a scsi-specific key from smartctl 7.3+. Try
        // grabbing this first, and fall back to the base method if it doesn't exist.
        return $this->smartData['scsi_model_name'] ?? parent::getModel();
    }

    public function getPowerCycleCount(): int
    {
        // SCSI drives report this in a SCSI-specific way, using the start/stop cycle counter log page
        return $this->smartData['scsi_start_stop_cycle_counter']['accumulated_start_stop_cycles'] ?? 0;
    }

    public function getHealthAttributes(): array
    {
        $attributes = [];
        $scsiErrors = $this->smartData['scsi_error_counter_log'] ?? [];

        foreach (['read', 'write', 'verify'] as $category) {
            $categoryErrors = $scsiErrors[$category] ?? [];
            foreach ($categoryErrors as $name => $value) {
                $attributes[] = new DriveHealthAttribute($category . '_' . $name, $value);
            }
        }

        return $attributes;
    }
}
