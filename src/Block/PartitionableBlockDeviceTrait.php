<?php

namespace Datto\Block;

use Datto\Common\Utility\Filesystem;

/**
 * Provides methods to retrieve partitions associated with block devices
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
trait PartitionableBlockDeviceTrait
{
    /**
     * Get the block device partitions associated with the block device
     *
     * @param string $blockDevicePath Full path of the block device
     * @param Filesystem $filesystem
     * @return array
     */
    private function getBlockDevicePartitions(string $blockDevicePath, Filesystem $filesystem): array
    {
        $globPattern = $this->getPartitionSuffixPattern($blockDevicePath);
        $partitions = $filesystem->glob($globPattern, GLOB_BRACE);

        if ($partitions === false) {
            $partitions = [];
        }

        return $partitions;
    }

    /**
     * Gets a glob pattern to use to scan for partition block devices.
     *
     * If a block device name ends with a non-digit, a partition delimiter is not used.
     * For example, /dev/mapper/5c55cf66f72211e880c1806e6f6e6963-crypt-a35c1f3a would have a partition name of
     *   /dev/mapper/5c55cf66f72211e880c1806e6f6e6963-crypt-a35c1f3a1
     *
     * If a block device name ends with a digit, a partition delimiter of 'p' is used.
     * For example, /dev/mapper/5c55cf66f72211e880c1806e6f6e6963-crypt-a35c1f38 would have a partition name of
     *   /dev/mapper/5c55cf66f72211e880c1806e6f6e6963-crypt-a35c1f38p1
     *
     * @param string $blockDevicePath Full path of the block device
     * @return string Glob pattern suitable to use with GLOB_BRACE syntax.
     */
    private function getPartitionSuffixPattern(string $blockDevicePath): string
    {
        $numbers = sprintf('{%s}', implode(',', range(1, 128)));
        $pattern = $blockDevicePath . $this->getPartitionDelimiter($blockDevicePath) . $numbers;
        return $pattern;
    }

    /**
     * Gets the expected partition delimiter to be used by a block device.
     *
     * If block device ends with a digit, the delimiter should be 'p',
     * otherwise no special delimiter is used.
     *
     * @param string $blockDevicePath Full path of the block device
     * @return string Partition delimiter
     */
    private function getPartitionDelimiter(string $blockDevicePath): string
    {
        $endsWithDigit = ctype_digit(substr($blockDevicePath, -1, 1));
        return $endsWithDigit ? 'p' : '';
    }
}
