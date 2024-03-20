<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;

/**
 * Utility to trigger partition scans.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Partprobe
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
     * Trigger a partition rescan
     *
     * @param string $blockDevice Full path of block device to trigger a scan on
     */
    public function triggerPartitionScan(string $blockDevice)
    {
        $this->processFactory->get(['partprobe', $blockDevice])
            ->mustRun();
    }
}
