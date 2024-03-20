<?php

namespace Datto\Core\Drives;

/**
 * An abstraction for an NVMe drive
 */
class NvmeDrive extends AbstractDrive
{
    public function getType(): string
    {
        return 'nvme';
    }

    public function getId(): string
    {
        $model_name = $this->smartData['model_name'] ?? null;
        $serial_number = $this->smartData['serial_number'] ?? null;

        if ($model_name && $serial_number) {
            // Otherwise, we can generate an ATA ID using the model and serial number
            $id = sprintf('nvme-%s_%s', str_replace(' ', '_', $model_name), $serial_number);
        } else {
            // Since we can't find any other identifying information just return the default ID
            $id = parent::getId();
        }
        return $id;
    }

    public function getHealthAttributes(): array
    {
        $attributes = [];
        $nvmeAttributes = $this->smartData['nvme_smart_health_information_log'] ?? [];
        foreach ($nvmeAttributes as $name => $value) {
            if (is_int($value)) {
                $attributes[] = new DriveHealthAttribute($name, $value);
            }
        }
        return $attributes;
    }
}
