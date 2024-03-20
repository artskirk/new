<?php

namespace Datto\System\Rsync;

use Datto\System\MonitorableProcess;
use Datto\System\MonitorableProcessProgress;

/**
 * Class MonitorableRsyncProcess Represents an rsync process that can be queried for progress updates.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class MonitorableRsyncProcess extends MonitorableProcess
{
    const MAX_RSYNC_ERRORS = 500;

    /** @var RsyncResults */
    private $results;

    /** @var  string */
    private $lastOutput;

    /**
     * Start process which can be monitored via calling instance
     *
     * @param string $source The directory to copy from
     * @param string $destination The directory to copy to
     * @param bool $perms If true rsync will copy posix user, group, and permissions.
     */
    public function startProcess(string $source, string $destination, bool $perms = true)
    {
        if ($this->isRunning()) {
            $this->logger->error("MRP0002 There is already a process associated with this object.");
            throw new \Exception("There is already a process associated with this object.");
        }
        $this->logger->info('MRP0003 Copying data', ['source' => $source, 'destination' => $destination]);
        if (!$source || !$this->filesystem->exists($source)) {
            $this->logger->error("MRP0004 Source directory does not exist");
            throw new \InvalidArgumentException("invalid source directory");
        }
        if (!$destination || !$this->filesystem->exists($destination)) {
            $this->logger->error("MRP0005 Destination directory does not exist");
            throw new \InvalidArgumentException("invalid destination directory");
        }

        $this->initialize();
        /*  Rsync Options
            --stats:  output basic stats on the transfer
            --inplace: perform copy operation directly on files -- makes error messages easier to understand
            -rltD: archive mode minus -pgo because they mess with ACL copying
            --delete: delete from destination if they no longer exist at source
            --exclude lost+found: special case, don't tamper with it
            --info=progress2 progress information
        */
        $pgo = $perms ? '-pgo' : '';
        $this->process = $this->processFactory
            ->getFromShellCommandLine('rsync --stats ' . $pgo . ' --inplace -rltD --delete --exclude lost+found --info=progress2 "${:SOURCE}" "${:DESTINATION}" && timeout 300 sync')
            ->setTimeout(null);

        $this->logger->debug("MRP0001 Running rsync: " . $this->process->getCommandLine());
        $this->process->start(null, ['SOURCE' => $source, 'DESTINATION' => $destination]);
    }

    /**
     * Get the most recent progress data for the process.
     *
     * @return MonitorableProcessProgress
     */
    public function getProgressData()
    {
        if ($this->isRunning()) {
            $this->setProgressData($this->process->getIncrementalOutput());
        } elseif ($this->process && !$this->results) {
            $this->getResults();
        }
        return $this->monitorableProcessProgress;
    }

    /**
     * Get the results of the process. If the process is still running, wait until it is done.
     *
     * @return RsyncResults
     */
    public function getResults()
    {
        if ($this->process && $this->isRunning()) {
            $this->process->wait();
        }

        if (!$this->results) {
            $output = $this->process->getIncrementalOutput();
            $errors = trim($this->process->getErrorOutput());
            $errorOutput = $errors ? explode(PHP_EOL, $errors, self::MAX_RSYNC_ERRORS) : [];

            // If there is no new output, the lastOutput variable will contain the stats output
            if ($output === "") {
                $output = $this->lastOutput;
            }
            $lastCarriageReturnIndex = strrpos($output, "\r");
            $firstNewlineIndex = strpos($output, "\n");

            if ($lastCarriageReturnIndex !== false) {
                $finalProgressOutput = substr($output, $lastCarriageReturnIndex, $firstNewlineIndex);
                $this->setProgressData($finalProgressOutput);
            }
            // At some point it might be nice to parse out each of the stats, but we're only using them for a log
            // message at the moment.
            $statsOutput = substr($output, $firstNewlineIndex);
            $exitCode = $this->process->getExitCode();

            $this->results = new RsyncResults($exitCode, $statsOutput, $errorOutput);
        }
        return $this->results;
    }

    /**
     * Given some amount of incremental rsync process output, parse it and set the progress data accordingly.
     *
     * @param string $processOutput Incremental process output
     */
    private function setProgressData($processOutput)
    {
        $lastCarriageReturnIndex = strrpos($processOutput, "\r");

        // If there is no carriage return, there has either been no new output or the only output present is the stats
        // output, so we set the lastOutput variable and do not update progress
        if ($lastCarriageReturnIndex === false) {
            $this->lastOutput = $processOutput;
        } else {
            $processOutput = trim(substr($processOutput, ++$lastCarriageReturnIndex));
            $processOutput = str_replace(',', '', $processOutput);
            $processOutput = preg_replace('/[ ]+/', ',', $processOutput);
            $outputArray = explode(',', $processOutput);

            if (count($outputArray) > 2) {
                $bytesTransferred = intval($outputArray[0]);
                $percentComplete = intval($outputArray[1]);
                $transferRate = $outputArray[2];
                $this->monitorableProcessProgress = new MonitorableProcessProgress($bytesTransferred, $percentComplete, $transferRate);
            }
        }
    }

    /**
     * Initialize private variables. Should be called in the constructor and in the startProcess function.
     */
    protected function initialize()
    {
        $this->monitorableProcessProgress = new MonitorableProcessProgress(0, 0, '0.0KB/s');
        $this->process = null;
        $this->results = null;
    }
}
