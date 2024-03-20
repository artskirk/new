<?php

namespace Datto\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Filesystem\SysFs;
use Datto\Log\LoggerFactory;
use Datto\Log\DeviceLoggerInterface;

/**
 * Provides various info about loop device.
 *
 * Such as:
 *  - path to loop block device
 *  - path to loop backing file
 *  - offset in the backing file the loop is pointing at
 *  - loop partition devices, e.g. loop0p1 loop0p2 etc
 *
 * @author Michael Meyer <mmeyer@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class LoopInfo
{
    private string $loopDevicePath;
    private ?string $backingFilePath;
    private ?int $offset;
    private Filesystem $filesystem;
    private SysFs $sysFs;

    /**
     * Loop constructor.
     *
     * @param string $loopDevicePath Path to the loop device (ex. /dev/loop0)
     * @param string|null $backingFilePath (Optional) Path to the backing file
     *  of the loop device
     * @param int|null offset (Optional) offset withing backing file the loop is
     *  pointing at
     * @param Filesystem|null $filesystem
     * @param SysFs|null $sysFs
     */
    public function __construct(
        $loopDevicePath,
        $backingFilePath = null,
        $offset = null,
        Filesystem $filesystem = null,
        SysFs $sysFs = null
    ) {
        // DI for info we have at creation time so we don't have to bug
        // sysfs for it if it's immediately available
        $this->loopDevicePath = $loopDevicePath;
        $this->backingFilePath = $backingFilePath;
        $this->offset = $offset;

        // DI mostly for unit-testing
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->sysFs = $sysFs ?: new SysFs($this->filesystem);
    }

    /**
     * Returns path to the loop device
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->loopDevicePath;
    }

    /**
     * Get the backing file the loop block device is pointing at.
     *
     * @return string|null
     */
    public function getBackingFilePath(): ?string
    {
        // lookup if not given via constructor
        if ($this->backingFilePath === null) {
            // todo: update loop backing file lookup to use losetup instead of sysfs
            // losetup --list --output BACK-FILE --noheadings /dev/loop0

            $this->backingFilePath = $this->sysFs->getLoopBackingFilePath(
                $this->getPath()
            );
        }

        return $this->backingFilePath;
    }

    /**
     * Return the offset of the backing file the loop device is pointing at.
     *
     * @return int
     */
    public function getOffset(): int
    {
        // lookup if not given via constructor
        if ($this->offset === null) {
            $this->offset = $this->sysFs->getLoopOffset($this->getPath());
        }

        return $this->offset;
    }

    /**
     * Get loop block devices that represent partitions, i.e. /dev/loop0p1
     *
     * @return array empty array if none.
     */
    public function getLoopPartitionDevices(): array
    {
        $parts = $this->filesystem->glob($this->loopDevicePath . 'p*');

        return $parts;
    }

    /**
     * Get path to the specified partition number under this loop
     * If the partition does not exist, false will be returned.
     *
     * @param int $number
     * @return string|false
     */
    public function getPathToPartition(int $number)
    {
        $partitionPath = $this->loopDevicePath . 'p' . $number;
        if ($this->filesystem->exists($partitionPath)) {
            return $partitionPath;
        } else {
            return false;
        }
    }

    /**
     * Calls getPath whenever it's used in string context.
     *
     * Particularly useful when porting legacy code to LoopManager where often
     * returned $loop is treated as string, e.g. in echo/log calls.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getPath();
    }
}
