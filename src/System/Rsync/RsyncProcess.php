<?php

namespace Datto\System\Rsync;

use Datto\Common\Resource\ProcessFactory;
use InvalidArgumentException;
use Datto\Log\DeviceLoggerInterface;

/**
 * Executes an rsync
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RsyncProcess
{
    const DEFAULT_TIMEOUT_IN_SECONDS = 60;

    /** @var DeviceLoggerInterface */
    private $logger;

    private ProcessFactory $processFactory;

    public function __construct(
        DeviceLoggerInterface $logger,
        ProcessFactory $processFactory = null
    ) {
        $this->logger = $logger;
        $this->processFactory = $processFactory ?: new ProcessFactory();
    }

    /**
     * Start the rsync process to transfer files to or from a remote server over ssh
     *
     * @param string $source
     * @param string $destination
     * @param int timeout In seconds
     * @param int $sshPort
     * @param bool $ignoreMissing whether to ignore misisng files on source
     */
    public function runOverSsh(
        string $source,
        string $destination,
        int $timeout = self::DEFAULT_TIMEOUT_IN_SECONDS,
        int $sshPort = 22,
        bool $ignoreMissing = false
    ) {
        $this->logger->info('MRP0003 Copying data', ['source' => $source, 'destination' => $destination]);
        if (!$source) {
            $this->logger->error("MRP0007 Source must be set");
            throw new InvalidArgumentException("invalid source directory");
        }
        if (!$destination) {
            $this->logger->error("MRP0008 Destination must be set");
            throw new InvalidArgumentException("invalid destination directory");
        }

        $command = [
            'rsync',
            '-a', // archive mode; equals -rlptgoD (no -H,-A,-X)
            "-e ssh -o StrictHostKeyChecking=no -p $sshPort",
            $source,
            $destination
        ];


        if ($ignoreMissing) {
            $command[] = '--ignore-missing-args';
        }

        $process = $this->processFactory
            ->get($command)
            ->setTimeout($timeout);

        $this->logger->debug("MRP0001 Running rsync: " . $process->getCommandLine());
        $process->mustRun();
    }
}
