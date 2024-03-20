<?php

namespace Datto\Utility\Block;

use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\AbstractUtility;
use \Exception;

/**
 * Utility to interact with the `resize2fs` command
 *
 * @author Nathan Blair <nblair@datto.com>
 */
class Resize2fs extends AbstractUtility
{
    const RESIZE = 'resize2fs';
    const RESIZE_ESTIMATE_FLAG = '-P';
    const TIMEOUT = 432000; // 5 days
    const REGEX_MINIMUM_SIZE = '/.+: ([0-9]+)/';

    private ProcessFactory $processFactory;

    /**
     * @param ProcessFactory $processFactory
     */
    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Calculate the minimum filesystem size for a given block device, which is expected to encapsulate a
     * valid ext filesystem.
     *
     * @param string $blockDevice the device to calculate the filesystem size for.
     *
     * @return int the minimum filesystem size, in bytes
     * @throws Exception if there is a problem getting the filesystem size
     */
    public function getMinimumSize(string $blockDevice): int
    {
        $process = $this->processFactory->get(
            [Resize2fs::RESIZE, $blockDevice, Resize2fs::RESIZE_ESTIMATE_FLAG],
            null,
            null,
            null,
            Resize2fs::TIMEOUT
        );

        $process->mustRun();

        $output = $process->getOutput();
        $this->logger->debug('RFS0001 resize2fs output', ['processOutput' => $output]);

        // Example output:
        // root@nblairubu:/mnt# resize2fs /dev/loop42p1 -P
        // resize2fs 1.46.3 (27-Jul-2021)
        // Estimated minimum size of the filesystem: 8872
        return $this->parseForSize($output, Resize2fs::REGEX_MINIMUM_SIZE);
    }
}
