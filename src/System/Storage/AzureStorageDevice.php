<?php

namespace Datto\System\Storage;

class AzureStorageDevice extends StorageDevice
{
    private string $azureManagedDeviceName;

    /**
     * Get the name of the Azure managed device backing this
     * storage device
     *
     * @return string
     */
    public function getAzureManagedDeviceName()
    {
        return $this->azureManagedDeviceName;
    }

    private function setAzureManagedDeviceName(string $name)
    {
        $this->azureManagedDeviceName = $name;
    }

    /**
     * Create from a StorageDevice object
     */
    public static function fromStorageDevice(
        StorageDevice $storageDevice,
        string $azureManagedDeviceName
    ): AzureStorageDevice {
        $device = new AzureStorageDevice(
            $storageDevice->getName(),
            $storageDevice->getModel(),
            $storageDevice->getCapacity(),
            $storageDevice->getSerial(),
            $storageDevice->getStatus(),
            $storageDevice->isVirtual(),
            $storageDevice->getShortName(),
            $storageDevice->isRotational(),
            $storageDevice->getSmartData(),
            $storageDevice->getId(),
            $storageDevice->getIds(),
            $storageDevice->getScsiHostNumber(),
            $storageDevice->getLunId()
        );

        $device->setAzureManagedDeviceName($azureManagedDeviceName);

        return $device;
    }
}
