<?php

namespace Datto\Device;

use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;

class Serial
{
    const SERIAL_SCRIPT = '/datto/scripts/mac.sh';

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(
        DeviceConfig $deviceConfig = null,
        ProcessFactory $processFactory = null
    ) {
        $this->deviceConfig = $deviceConfig ?? new DeviceConfig();
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * Get the device's serial.
     * For non-azure devices this is the mac address of eth0 that was present during activation/imaging.
     * For azure devices the value returned does not correspond to the mac address of any network adapter
     * but still uses the same format.
     *
     * This may either be a value stored in /datto/config/serial OR the mac address of eth0.
     *     Note that if /datto/config/serial does not exist, using the mac of eth0 is only a guess since eth0 is not
     *     guaranteed to refer to the same physical network adapter.
     *
     * @return string Serial number of the device. Ex: 00012e7acb86
     */
    public function get(): string
    {
        $process = $this->processFactory->get([self::SERIAL_SCRIPT]);

        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * Manually override the serial of the device. Used by Azure to explictly set the serial during activation.
     */
    public function override(string $serial)
    {
        $this->deviceConfig->set(DeviceConfig::KEY_SERIAL, $serial);
    }
}
