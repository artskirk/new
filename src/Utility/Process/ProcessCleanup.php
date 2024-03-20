<?php

namespace Datto\Utility\Process;

use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Utility\File\Lsof;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Provides different ways to cleanup processes that are hanging onto resources
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class ProcessCleanup
{
    const KILL_WAIT_SEC = 5;

    /** @var Lsof */
    private $lsof;
    /** @var PosixHelper */
    private $posixHelper;
    /** @var Sleep */
    private $sleep;
    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Lsof $lsof,
        PosixHelper $posixHelper,
        Sleep $sleep,
        Filesystem $filesystem
    ) {
        $this->lsof = $lsof;
        $this->posixHelper = $posixHelper;
        $this->sleep = $sleep;
        $this->filesystem = $filesystem;
    }

    /**
     * Wait until the given directory is not busy.
     *
     * @param string|null $directory  The directory to check if processes are current using
     * @param int $timeoutSeconds  The length of time to wait for all processes to stop using the directory
     */
    public function waitUntilDirectoryNotBusy(
        $directory,
        DeviceLoggerInterface $logger,
        int $timeoutSeconds = 60
    ) {
        try {
            if (is_null($directory) || !$this->filesystem->exists($directory)) {
                return;
            }

            $timeoutMilliseconds = $timeoutSeconds * 1000;
            $alreadyLogged = false;
            $isBusy = false;
            $pids = [];
            $currentProcessId = $this->posixHelper->getCurrentProcessId();
            while (true) {
                $pids = $this->getPidsExcludingCurrentProcess($directory, $currentProcessId, !$alreadyLogged, $logger);
                $isBusy = count($pids) > 0;

                if (!$isBusy || $timeoutMilliseconds <= 0) {
                    break;
                }

                $this->sleep->msleep(100);
                $timeoutMilliseconds -= 100;
            }

            if ($isBusy) {
                foreach ($pids as $pid) {
                    $processCommandLine = $this->getProcessCommandLine($pid);
                    $logger->info(
                        'PCS0002 Directory is still busy after waiting',
                        ['timeoutSeconds' => $timeoutSeconds, 'pid' => $pid, 'processCommandLine' => $processCommandLine, 'directory' => $directory]
                    );
                }
            }
        } catch (Throwable $e) {
            $logger->error('PCS0007 Error waiting for directory to not be busy.', ['directory' => $directory, 'exception' => $e]);
        }
    }

    public function logProcessesUsingDirectory($directory, DeviceLoggerInterface $logger)
    {
        $this->waitUntilDirectoryNotBusy($directory, $logger, 0);
    }

    /**
     * Attempts to kill all processes that are currently using the directory
     *
     * @param string|null $directory  The directory to check if processes are current using
     */
    public function killProcessesUsingDirectory($directory, DeviceLoggerInterface $logger)
    {
        try {
            if (is_null($directory) || !$this->filesystem->exists($directory)) {
                return;
            }

            $currentProcessId = $this->posixHelper->getCurrentProcessId();
            $pids = $this->getPidsExcludingCurrentProcess($directory, $currentProcessId, true, $logger);
            foreach ($pids as $pid) {
                $processCommandLine = $this->getProcessCommandLine($pid);
                $logger->debug(
                    'PCS0004 Killing pid using directory',
                    ['pid' => $pid, 'processCommandLine' => $processCommandLine, 'directory' => $directory]
                );
                $this->posixHelper->kill($pid, PosixHelper::SIGNAL_TERM);
                if ($this->posixHelper->isProcessRunning($pid)) {
                    $this->sleep->sleep(self::KILL_WAIT_SEC);
                    $this->posixHelper->kill($pid, PosixHelper::SIGNAL_KILL);
                }
            }

            $pids = $this->getPidsExcludingCurrentProcess($directory, $currentProcessId, false, $logger);
            if (count($pids) > 0) {
                foreach ($pids as $pid) {
                    $processCommandLine = $this->getProcessCommandLine($pid);
                    $logger->error(
                        'PCS0006 Was unable to kill pid using directory',
                        ['pid' => $pid, 'processCommandLine' => $processCommandLine, 'directory' => $directory]
                    );
                }
            }
        } catch (Throwable $e) {
            $logger->error('PCS0005 Error killing processes using directory', ['directory' => $directory, 'exception' => $e]);
        }
    }

    /**
     * Return the full command line that started the given process ID
     */
    private function getProcessCommandLine(int $pid): string
    {
        return str_replace("\0", ' ', $this->filesystem->fileGetContents("/proc/$pid/cmdline"));
    }

    /**
     * Get the list of pids that are using the given directory, other than the provided current process ID
     * @return int[] list of pids using the directory
     */
    private function getPidsExcludingCurrentProcess(
        $directory,
        int $currentProcessId,
        bool $shouldLogCurrentProcessWarning,
        DeviceLoggerInterface $logger
    ): array {
        $pids = $this->lsof->getPids($directory);
        if (($key = array_search($currentProcessId, $pids)) !== false) {
            if ($shouldLogCurrentProcessWarning) {
                $logger->debug('PCS0001 Lsof says our process is using the directory', ['directory' => $directory, 'pid' => $currentProcessId]);
            }
            unset($pids[$key]);
        }

        return $pids;
    }
}
