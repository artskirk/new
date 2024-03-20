<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use RuntimeException;

/**
 * Utility for listing information for block devices using lsblk.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class LsBlk
{
    const COMMAND = 'lsblk';
    const FIELDS = 'name,type,tran,rota,hctl';

    private ProcessFactory $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Get the list of available internal disk drives, i.e. SATA, NVMe, and SAS, but not loops, ZFS, or USB devices.
     *
     * @return BlockDevice[]
     */
    public function getDiskDrives(): array
    {
        $devices = $this->getBlockDevices();
        return array_filter($devices, function (BlockDevice $device) {
            $isDiskWithTransport = $device->isDisk() && $device->hasTransport();
            return $isDiskWithTransport && !$device->isUsb();
        });
    }

    /**
     * Get data for the specified block device.
     *
     * @param string $devicePath
     *
     * @return BlockDevice
     */
    public function getBlockDeviceByPath(string $devicePath): BlockDevice
    {
        $devices = $this->getBlockDevices();
        $blockDevice = array_values(array_filter($devices, function (BlockDevice $device) use ($devicePath) {
            return $device->getPath() === $devicePath;
        }));

        if (empty($blockDevice)) {
            throw new RuntimeException("Could not find block device with path '$devicePath'");
        }

        return $blockDevice[0];
    }

    /**
     * Returns all the block devices on the system.
     *
     * @return BlockDevice[]
     */
    public function getBlockDevices(): array
    {
        $command = [
            self::COMMAND,
            '--json',
            '--nodeps',
            '--paths',
            '--output',
            self::FIELDS,
        ];
        $process = $this->processFactory->get($command);
        $process->mustRun();

        $result = json_decode($process->getOutput(), true);
        $blockDevices = $result['blockdevices'] ?? [];

        $deviceList = [];
        foreach ($blockDevices as $device) {
            $deviceList[] = new BlockDevice(
                $device['name'],
                $device['type'] ?? '',
                $device['tran'] ?? '',
                (bool)$device['rota'],
                $this->getHostFromHctl($device['hctl']),
                $this->getLunFromHctl($device['hctl'])
            );
        }

        return $deviceList;
    }

    /**
     * @param string|null $hctlStr
     *
     * @return int|null
     */
    private function getHostFromHctl($hctlStr)
    {
        if (empty($hctlStr)) {
            return null;
        }

        $hctl = explode(':', $hctlStr);  // hctl = Host:Channel:Target:LUN
        return empty($hctl) ? null : (int) $hctl[0];
    }

    /**
     * @param string|null $hctlStr
     *
     * @return int|null
     */
    private function getLunFromHctl($hctlStr)
    {
        if (empty($hctlStr)) {
            return null;
        }

        $hctl = explode(':', $hctlStr);  // hctl = Host:Channel:Target:LUN
        return empty($hctl) ? null : (int) $hctl[array_key_last($hctl)];
    }
}
