<?php

namespace Datto\Service\Device;

/**
 * Provides file where data can be read from/written to for a particular
 * device.
 *
 * For sd devices the SMART-capable device and the data storage device are
 * represented by the same file, e.g. /dev/sda. In case of NVMe devices
 * /dev/nvme0 is the SMART device, but /dev/nvme0n1 is where the data is.
 */
class BlockDeviceFileFinder
{
    private const TYPE_CHARACTER_DEVICE = 'char';
    private const TYPE_BLOCK_DEVICE = 'block';

    public function getBlockFile(string $device): string
    {
        $deviceType = filetype($device);
        switch ($deviceType) {
            case self::TYPE_BLOCK_DEVICE:
                return $device;

            case self::TYPE_CHARACTER_DEVICE:
                return $this->getBlockFileForCharacterDevice($device);

            default:
                throw new \LogicException('Unsupported device');
        }
    }

    private function getBlockFileForCharacterDevice(string $device): string
    {
        $nvmeNamespace = $device . 'n1';

        if (filetype($nvmeNamespace) === self::TYPE_BLOCK_DEVICE) {
            return $nvmeNamespace;
        }

        throw new \LogicException('Unsupported device');
    }
}
