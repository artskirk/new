<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;

/**
 * Wrapper for the 'lsof' (list open files) command, including output
 * parsing and easy output filtering.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Lsof
{
    /** @var ProcessFactory */
    private $processFactory;

    public function __construct(
        ProcessFactory $processFactory = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * @param string $processName
     * @return LsofEntry[]
     */
    public function getFilesByProcessName(string $processName): array
    {
        return $this->getFiles([
            '-c',
            $processName
        ]);
    }

    /**
     * Get open processes in the given directory (lsof +D ...).
     *
     * If $filterCallback is passed, it is called for every entry, and
     * only if 'true' is returned, it is included in the result.
     *
     * @param string $directory
     * @param callable $filterCallback
     * @return LsofEntry[]
     */
    public function getFilesInDir($directory, $filterCallback = null)
    {
        return $this->getFiles(['+D', $directory], $filterCallback);
    }

    /**
     * Get open processes using the lsof command. The $options array
     * is passed directly to the command.
     *
     * If $filterCallback is passed, it is called for every entry, and
     * only if 'true' is returned, it is included in the result.
     *
     * @param string[] $options
     * @param callable $filterCallback
     * @return LsofEntry[]
     */
    public function getFiles(array $options, $filterCallback = null)
    {
        $process = $this->processFactory->get(array_merge(['lsof'], $options));

        $process->run();

        $entries = [];
        $output = explode("\n", $process->getOutput());

        foreach ($output as $line) {
            $entry = $this->parseEntry($line);

            if ($entry) {
                $includeEntry = !$filterCallback || call_user_func($filterCallback, $entry);

                if ($includeEntry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * Get process ids of processes using the given file or directory
     *
     * @param string $file
     * @return int[]
     */
    public function getPids(string $file): array
    {
        $process = $this->processFactory->get([
                'lsof',
                '-tn', // terse (no headers, pids only), inhibit network number conversion (speed improvement)
                $file
            ]);

        $process->run();

        if (preg_match_all('/^(\d+)$/m', $process->getOutput(), $matches)) {
            return $matches[1];
        }

        return [];
    }

    /**
     * Parses an 'lsof' line and returns a LsofEntry.
     *
     * @param string $line
     * @return LsofEntry|null
     */
    private function parseEntry($line)
    {
        // COMMAND     PID       USER   FD   TYPE DEVICE SIZE/OFF NODE NAME
        // avahi-dae  1331      avahi  cwd    DIR   8,33     4096 6202 /etc/avahi
        // ...

        if (preg_match('/^(\S+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)$/i', $line, $m)) { // The \d+ filters the header line!
            return new LsofEntry($m[1], $m[2], $m[3], $m[4], $m[5], $m[6], $m[7], $m[8], $m[9]);
        } else {
            return null;
        }
    }
}
