<?php

namespace Datto\Core\Drives;

/**
 * A Drive abstraction for a Serial ATA drive, including SATA HDDs and SSDs.
 */
class SataDrive extends AbstractDrive
{
    public const TYPE = 'sata';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getId(): string
    {
        $wwn = $this->smartData['wwn'] ?? null;
        $model_name = $this->smartData['model_name'] ?? null;
        $serial_number = $this->smartData['serial_number'] ?? null;

        if ($wwn) {
            // If a WWN is present, prefer that for the ID. The JSON payload reports the
            // components in decimal, but they should be displayed in hex
            $id = sprintf('wwn-0x%x%06x%09x', $wwn['naa'], $wwn['oui'], $wwn['id']);
        } elseif ($model_name && $serial_number) {
            // Otherwise, we can generate an ATA ID using the model and serial number
            $id = sprintf('ata-%s_%s', str_replace(' ', '_', $model_name), $serial_number);
        } else {
            // Since we can't find any other identifying information just return the default ID
            $id = parent::getId();
        }
        return $id;
    }

    public function getHealthAttributes(): array
    {
        $attributes = [];
        $smartAttributes = $this->smartData['ata_smart_attributes']['table'] ?? [];
        foreach ($smartAttributes as $smartAttribute) {
            $attributes[] = new DriveHealthAttribute(
                $smartAttribute['name'],
                $smartAttribute['value'],
                $smartAttribute['id'] ?? 0,
                $smartAttribute['thresh'] ?? 0,
                $smartAttribute['raw']['value'] ?? null
            );
        }
        return $attributes;
    }
}
