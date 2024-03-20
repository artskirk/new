<?php

namespace Datto\System\Pv;

use Datto\System\MonitorableProcess;
use Datto\System\MonitorableProcessProgress;
use Datto\Utility\ByteUnit;
use Exception;
use InvalidArgumentException;

/**
 * Class MonitorablePvProcess handles running and returning progress data on long running pv processes
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class MonitorablePvProcess extends MonitorableProcess
{
    /** @var  PvResults */
    private $pvResults;

    /** @var  int */
    private $fileSize;

    /**
     * Start PV process which can be monitored by this class instance
     *
     * @param $sourceFile string
     * @param $destination string
     * @param $perms bool
     */
    public function startProcess(string $source, string $destination, bool $perms = false)
    {
        if ($this->isRunning()) {
            $this->logger->error("MPP0001 There is already a running process associated with this object");
            throw new Exception("There is already a running process associated with this object.");
        }
        $this->logger->info('MPP0002 Copying data', ['source' => $source, 'destination' => $destination]);
        if (!$source || !$this->filesystem->exists($source)) {
            $this->logger->error("MPP0003 Source does not exist");
            throw new InvalidArgumentException("invalid source");
        }
        if (!dirname($destination) || !$this->filesystem->exists(dirname($destination))) {
            $this->logger->error("MPP0004 Destination does not exist");
            throw new InvalidArgumentException("invalid destination");
        }
        if ($perms) {
            $this->logger->warning('MPP0006 Permissions copy requested but currently not supported.');
        }

        $this->initialize();

        // NOTE: The "exec" is needed because Symfony spawns the process using "sh -c pv ..."
        // Without the exec, killing the process will only kill sh and leave pv running.
        $this->process = $this->processFactory
            ->getFromShellCommandLine('exec pv -nbtf "${:SOURCE}" > "${:DESTINATION}"')
            ->setTimeout(null);

        $this->logger->debug('MPP0005 Running pv: ' . $this->process->getCommandLine());
        $this->process->start(null, ['SOURCE' => $source, 'DESTINATION' => $destination]);

        $this->fileSize = $this->filesystem->getSize($source);
    }

    /**
     * Return PvResults object which allows fetching of error text, error codes, and interpreted error codes
     *
     * @return PvResults
     */
    public function getResults(): PvResults
    {
        if ($this->process && $this->isRunning()) {
            $this->process->wait();
        }

        if (!$this->pvResults) {
            $output = $this->process->getIncrementalErrorOutput();
            $errors = trim($this->process->getErrorOutput());
            $errorOutput = $errors ? explode(PHP_EOL, $errors) : [];

            $this->setProgressData($output);
            $exitCode = $this->process->getExitCode();

            $this->pvResults = new PvResults($exitCode, $errorOutput);
        }
        return $this->pvResults;
    }

    /**
     * Return MonitorableProcessProgress object which allows fetching of data transferred, date xfer rate, and percent
     *
     * @return MonitorableProcessProgress
     */
    public function getProgressData(): MonitorableProcessProgress
    {
        if ($this->isRunning()) {
            $this->setProgressData($this->process->getIncrementalErrorOutput());
        } elseif ($this->process && !$this->pvResults) {
            $this->getResults();
        }
        return $this->monitorableProcessProgress;
    }

    /**
     * Given some amount of incremental process output, parse it and set the progress data accordingly.
     *
     * @param string $processOutput Incremental process output
     */
    private function setProgressData(string $processOutput)
    {
        if ($processOutput) {
            $processOutputArray = explode(PHP_EOL, trim($processOutput));
            $outputArray = explode(' ', array_pop($processOutputArray));
            if (count($outputArray) == 2) {
                $timeElapsed = $outputArray[0];
                $dataTransferred = $outputArray[1];
                $rate = floor(($dataTransferred / $timeElapsed));
                $percent = (int)(($dataTransferred / $this->fileSize) * 100);
                $rateInKb = ceil(ByteUnit::BYTE()->toKiB($rate));
                $formattedRate = $rateInKb <= ByteUnit::MIB()->toKiB(1)
                    ? ($rateInKb . "KB/s")
                    : (ceil(ByteUnit::KIB()->toMiB($rateInKb)) . "MB/s");

                $this->monitorableProcessProgress = new MonitorableProcessProgress(
                    $dataTransferred,
                    $percent,
                    $formattedRate
                );
            }
        }
    }

    /**
     * Initialize private variables. Should be called in the constructor and in the startProcess function.
     */
    protected function initialize()
    {
        $this->monitorableProcessProgress = new MonitorableProcessProgress(0, 0, '0B/s');
        $this->pvResults = null;
    }
}
