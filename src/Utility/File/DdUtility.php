<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Utility class for running dd commands.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class DdUtility
{
    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param ProcessFactory $processFactory
     * @param Filesystem $filesystem
     */
    public function __construct(ProcessFactory $processFactory, Filesystem $filesystem)
    {
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
    }

    /**
     * Creates a sparse file.
     *
     * @param string $outputFile
     * @param int $blockSize
     * @param int $skipBlocks
     */
    public function createSparseFile(string $outputFile, int $blockSize, int $skipBlocks)
    {
        if ($this->filesystem->exists($outputFile)) {
            throw new Exception("Output file exists");
        }
        $commandLine = [
            "dd",
            "if=/dev/null",
            "bs=$blockSize",
            "of=$outputFile",
            "seek=$skipBlocks",
        ];
        $process = $this->processFactory->get($commandLine);
        $process->mustRun();
    }
}
