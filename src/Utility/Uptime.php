<?php

namespace Datto\Utility;

use Datto\Common\Resource\ProcessFactory;

/**
 * Utility to get the uptime of the system.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Uptime
{
    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Get an epoch of when the device was booted.
     *
     * @return int
     */
    public function getBootedAt(): int
    {
        $process = $this->processFactory->get([
                'stat',
                '-c',
                '%Z',
                '/proc/'
            ]);

        $process->mustRun();

        return (int)trim($process->getOutput());
    }
}
