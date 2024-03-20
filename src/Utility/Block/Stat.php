<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\AbstractUtility;

/**
 * Utility to interact with the `stat` command.
 *
 * @author Nathan Blair <nblair@datto.com>
 */
class Stat extends AbstractUtility
{
    const STAT = 'stat';
    const STAT_FILESYSTEM = '--file-system';
    // %s means print the block size in file-system mode - https://linux.die.net/man/1/stat
    const STAT_FORMAT = '--format=%s';

    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Get the block size for an ext filesystem under a given block device.
     * @param string $blockDevice
     * @return int
     */
    public function getFilesystemBlockSize(string $blockDevice): int
    {
        // Example output:
        // root@nblairubu:/mnt# stat --file-system /dev/loop42p1 --format=%s
        // 4096

        $process = $this->processFactory->get([Stat::STAT, Stat::STAT_FILESYSTEM, $blockDevice, Stat::STAT_FORMAT]);
        $process->mustRun();
        return (int)trim($process->getOutput());
    }
}
