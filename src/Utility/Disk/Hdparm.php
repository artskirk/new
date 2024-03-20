<?php

namespace Datto\Utility\Disk;

use Datto\Common\Resource\ProcessFactory;

/**
 * Utility to trigger disk scans.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Hdparm
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
     * Trigger a disk rescan
     *
     * @param string $blockDevice Full path of block device to trigger a scan on
     */
    public function triggerDiskRescan(string $blockDevice)
    {
        $this->processFactory->get(['hdparm', '-z', $blockDevice])
            ->mustRun();
    }
}
