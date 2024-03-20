<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;

/**
 * Wrapper around the binary "tar". Used for archiving and compressing files.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Tar
{
    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    /**
     * Extract a compressed tar (eg. '.tar.gz') to a destination directory.
     *
     * @param string $tarFile
     * @param string $destinationDir
     *      Note: $destinationDir must exist and be writable otherwise tar will fail.
     */
    public function extract($tarFile, $destinationDir)
    {
        $process = $this->processFactory->get([
                'tar',
                '-C',
                $destinationDir,
                '-xzvf',
                $tarFile
            ]);
        $process->mustRun();
    }
}
