<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\AbstractUtility;

/**
 * Utility to interact with a block device.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Blockdev extends AbstractUtility
{
    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Flush buffers
     *
     * @param string $blockDevice Full path of block device to flush
     */
    public function flushBuffers(string $blockDevice)
    {
        $this->processFactory->get(['blockdev', '--flushbufs', $blockDevice])
            ->mustRun();
    }

    /**
     * Get the size of the block device in sectors
     *
     * @param string $blockDevice
     * @return int Size in sectors
     */
    public function getSizeInSectors(string $blockDevice): int
    {
        $process = $this->processFactory->get(['blockdev', '--getsz', $blockDevice]);
        $process->mustRun();
        $sizeInSectors = (int)trim($process->getOutput());
        return $sizeInSectors;
    }

    /**
     * Set the block device to read only
     *
     * @param string $blockDevice
     */
    public function setReadOnly(string $blockDevice)
    {
        $process = $this->processFactory->get(['blockdev', '--setro', $blockDevice]);
        $process->mustRun();
    }

    /**
     * Gets the partition size for the given path, in bytes.
     *
     * @param string $blockDevice
     * @return int the partition size, in bytes.
     */
    public function getSizeInBytes(string $blockDevice) : int
    {
        $process = $this->processFactory->get(['blockdev', '--getsize64', $blockDevice]);
        $process->mustRun();
        return (int)trim($process->getOutput());
    }
}
