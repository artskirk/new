<?php

namespace Datto\Utility\Process;

use Datto\Common\Resource\ProcessFactory;
use Exception;

/**
 * Wrapper for the binary "ps" (provided by "procps" package), which print information about running processes.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Ps
{
    /** @var ProcessFactory */
    private $processFactory;

    /**
     * @param ProcessFactory|null $processFactory
     */
    public function __construct(ProcessFactory $processFactory = null)
    {
        $this->processFactory = $processFactory ?? new ProcessFactory();
    }

    /**
     * Fuzzy search for PIDs via. the command they were started with.
     *
     * @param string $pattern
     * @return int[]
     */
    public function getPidsFromCommandPattern(string $pattern): array
    {
        $pids = [];

        $process = $this->processFactory->get([
                'ps',
                '-eo',
                'pid,cmd'
            ]);
        $process->mustRun();

        $lines = explode("\n", $process->getOutput());
        foreach ($lines as $line) {
            if (preg_match('/(\d+)\s+(.+)$/', $line, $matches)) {
                $pid = (int)$matches[1];
                $commandLine = $matches[2];

                if (preg_match($pattern, $commandLine)) {
                    $pids[] = $pid;
                }
            }
        }

        return $pids;
    }

    /**
     * @param int[] $pids
     * @return PsEntry[]
     */
    public function getByPids(array $pids)
    {
        $entries = [];

        $process = $this->processFactory->get([
                'ps',
                '-e',
                '-o',
                'pid,etimes'
            ]);
        $process->mustRun();

        $lines = explode("\n", $process->getOutput());
        foreach ($lines as $line) {
            if (preg_match('/(\d+)\s+(\d+)$/', $line, $matches)) {
                $pid = (int)$matches[1];
                $runtimeInSeconds = (int)$matches[2];

                if (in_array($pid, $pids)) {
                    $entries[] = new PsEntry($pid, $runtimeInSeconds);
                }
            }
        }

        return $entries;
    }

    /**
     * @param int $pid
     * @return PsEntry
     */
    public function getFirstByPid(int $pid)
    {
        $entries = $this->getByPids([$pid]);

        if (!empty($entries[0])) {
            return $entries[0];
        } else {
            throw new Exception("Could not find process by pid: $pid");
        }
    }
}
