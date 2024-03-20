<?php

namespace Datto\Virtualization;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Service that cleans up virtualization remnants in the shared memory folder.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class SharedMemoryCleanupService
{
    const SHARED_MEMORY_PATH = '/dev/shm/';

    private ProcessFactory $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
    }

    /**
     * Removes any Spice files which are not in use for active virtualizations.
     */
    public function cleanupSharedMemory()
    {
        $this->logger->debug('VIR0101 Virtualization shared memory cleanup started');

        $filesToRemove = $this->determineInactiveFiles();
        if ($filesToRemove) {
            $this->logger->info('VIR0102 Removing inactive Spice files', ['numberOfFiles' => count($filesToRemove)]);

            foreach ($filesToRemove as $file) {
                $removed = $this->filesystem->unlink($file);
                if (!$removed) {
                    $this->logger->error('VIR0105 Failed to remove shared memory file', ['filename' => $file]);
                }
            }
        } else {
            $this->logger->debug('VIR0104 No inactive Spice files to remove');
        }

        $this->logger->debug('VIR0103 Virtualization shared memory cleanup completed');
    }

    /**
     * Determines which shared files can be safely deleted.
     *
     * @return string[] Files which can be removed
     */
    private function determineInactiveFiles()
    {
        $inactiveFiles = array();

        $command = "ls " . self::SHARED_MEMORY_PATH .
            " | grep -E 'spice' | awk '{print \"" . self::SHARED_MEMORY_PATH . "\" $1}'";
        $result = $this->executeCommand($command);

        if ($result) {
            $filesInUse = $this->findFilesInUse();
            $inactiveFiles = array_diff($result, $filesInUse);
        }

        return $inactiveFiles;
    }

    /**
     * Finds which shared files are currently in use for a virtualization.
     *
     * @return string[] Files currently in use
     */
    private function findFilesInUse()
    {
        $filesInUse = array();

        $command = "lsof +d " . self::SHARED_MEMORY_PATH . " | grep -E 'spice' | awk '{print $3, $9}'";
        $result = $this->executeCommand($command);

        foreach ($result as $line) {
            if (preg_match('/(\S+)\s(\S+)/', $line, $matches)) {
                $user = $matches[1];
                $path = $matches[2];

                // Files in use by the libvirt-qemu user are active virtualizations
                if ($user === 'libvirt-qemu' && !in_array($path, $filesInUse)) {
                    $filesInUse[] = $path;
                }
            }
        }

        return $filesInUse;
    }

    /**
     * Runs the given command using a ProcessFactory.
     *
     * @param string $command The command to run
     * @return string[] Output of the command
     */
    private function executeCommand($command)
    {
        $result = array();

        $process = $this->processFactory->getFromShellCommandLine($command);
        $process->mustRun();

        $output = trim($process->getOutput());
        if ($output !== '') {
            $result = explode(PHP_EOL, $output);
        }

        return $result;
    }
}
