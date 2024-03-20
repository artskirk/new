<?php

namespace Datto\Utility;

use Datto\Common\Resource\ProcessFactory;

/**
 * Class to retrieve debian package information
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Package
{
    /** ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory)
    {
        $this->processFactory = $processFactory;
    }

    public function getPackageVersion(string $packageName): string
    {
        $command = [
            'dpkg-query',
            '--show',
            '--showformat=${Version}',
            $packageName
        ];

        $process = $this->processFactory->get($command);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
