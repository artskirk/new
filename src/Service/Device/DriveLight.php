<?php

namespace Datto\Service\Device;

use Datto\Common\Resource\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class DriveLight
{
    private ProcessFactory $processFactory;
    private BlockDeviceFileFinder $blockDeviceFileFinder;

    public function __construct(ProcessFactory $processFactory, BlockDeviceFileFinder $blockDeviceFileFinder)
    {
        $this->processFactory = $processFactory;
        $this->blockDeviceFileFinder = $blockDeviceFileFinder;
    }

    /**
     * Lights up the drive light of the given drive for the given
     * amount of time.
     *
     * @param string $drive Drive string, e.g. sda1 for /dev/sda1
     * @param int $timeout Time in seconds to light the device light
     * @return bool Defaults to true, unless the timeout is not reached due to an early failure
     */
    public function blink(string $drive, int $timeout = 10): bool
    {
        $readableDevice = $this->blockDeviceFileFinder->getBlockFile("/dev/$drive");

        try {
            $process = $this->processFactory->get([
                'dd',
                "if=$readableDevice",
                'of=/dev/null',
                'iflag=direct'
            ])->setTimeout($timeout);

            $process->run();
        } catch (ProcessTimedOutException $timedOutException) {
            // We expect the timeout to be reached - that's what we expect to stop the process
            return true;
        }

        return false;
    }
}
