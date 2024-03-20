<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;

/**
 * Utility to make fdisk calls.
 *
 * @author Afeique Sheikh <asheikh@datto.com>
 */
class Fdisk
{
    /** @var ProcessFactory */
    private $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * List the partitions for the specified device
     *
     * @param string $device
     * @return string Partition table
     */
    public function listPartitionTables(string $device)
    {
        $process = $this->processFactory->get(["fdisk", "-l", $device]);
        $process->run();
        return $process->getOutput();
    }
}
