<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;

/**
 * Wrapper for the binary "tail"
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class Tail
{
    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Get the last lines of a file
     *
     * @param string $filename
     * @param int $lines Lines to get
     * @return string
     */
    public function getLines(string $filename, int $lines): string
    {
        $process = $this->processFactory->get(['tail', '-n', $lines, $filename]);
        $process->run();

        return trim($process->getOutput());
    }
}
