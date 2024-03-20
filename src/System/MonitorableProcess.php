<?php

namespace Datto\System;

use Datto\Common\Resource\Process;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * MonitorableProcess class contains vars and methods required by various processes that can be monitored
 * and return progress information
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
abstract class MonitorableProcess
{
    /** @var ProcessFactory */
    protected $processFactory;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var Filesystem */
    protected $filesystem;

    /** @var  Process */
    protected $process;

    /** @var MonitorableProcessProgress */
    protected $monitorableProcessProgress;

    public function __construct(
        DeviceLoggerInterface $logger,
        ProcessFactory $processFactory,
        Filesystem $filesystem
    ) {
        $this->logger = $logger;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;

        $this->initialize();
    }

    /**
     * @param string $source
     * @param string $destination
     * @param bool $perms
     * @return mixed
     */
    abstract public function startProcess(string $source, string $destination, bool $perms);

    /**
     * Return Results object
     * @return MonitorableProcessResults
     */
    abstract public function getResults();

    /**
     * Return Progress object
     * @return MonitorableProcessProgress
     */
    abstract public function getProgressData();

    /**
     * Initialize required vars and set 'empty' process-specific Progress object
     */
    abstract protected function initialize();

    /**
     * Kill the running process and return the results
     *
     * @return MonitorableProcessResults
     */
    public function killProcess(): MonitorableProcessResults
    {
        if (!$this->isRunning()) {
            $this->logger->error("MNP0006 Attempted to kill the process, but no process is running.");
            throw new \Exception("Attempted to kill the process, but no process is running.");
        }
        $this->process->stop();
        return $this->getResults();
    }

    /**
     * Determine whether or not the process is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process && $this->process->isRunning();
    }
    
    /**
     * Return string containing error output from the process
     *
     * @return string
     */
    public function getErrorOutput(): string
    {
        return $this->process->getErrorOutput();
    }
}
