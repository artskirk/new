<?php

namespace Datto\Nfs;

use Datto\Common\Resource\ProcessFactory;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Utility\File\LockFactory;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handle (enable/disable/get state) NFS sharing via the nfs exports file
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class NfsExportManager
{
    const EXPORTS_FILE = '/etc/exports';

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var LockFactory */
    private $lockFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    public function __construct(
        ProcessFactory $processFactory = null,
        Filesystem $filesystem = null,
        LockFactory $lockFactory = null,
        DeviceLoggerInterface $logger = null
    ) {
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->filesystem = $filesystem ?? new Filesystem($this->processFactory);
        $this->lockFactory = $lockFactory ?? new LockFactory();
        $this->logger = $logger ?? LoggerFactory::getDeviceLogger();
    }

    /**
     * Enables NFS share by adding its' path to the NFS exports file
     *
     * @param string $path path of the directory to be NFS enabled
     * @param array $options
     * @return bool
     */
    public function enable(string $path, array $options = []): bool
    {
        $this->logger->debug('NFS0001 Enabling the NFS share at ' . $path);
        $defaultOptions = [
            'host' => '0.0.0.0/0.0.0.0',
            'mode' => 'rw',
            'flags' => 'no_subtree_check,all_squash,fsid=' . md5($path),
        ];

        $options = array_merge($defaultOptions, $options);

        if ($this->isEnabled($path, $options['host'])) {
            return true;
        }

        $lock = $this->lockFactory->create(static::EXPORTS_FILE);
        $lock->assertExclusiveAllowWait(1);

        $this->filesystem->filePutContents(
            static::EXPORTS_FILE,
            sprintf(
                '%s    %s(%s,%s)' . PHP_EOL,
                $path,
                $options['host'],
                $options['mode'],
                $options['flags']
            ),
            FILE_APPEND
        );

        $lock->unlock();

        $this->applyChanges();

        return $this->isEnabled($path);
    }

    /**
     * Disables NFS share by removing its' path from the NFS exports file
     *
     * @param string $path path of the directory to be NFS disabled
     * @return bool
     */
    public function disable(string $path): bool
    {
        $this->logger->debug('NFS0002 Disabling the NFS share at ' . $path);
        $isEnabled = $this->isEnabled($path);

        if (!$isEnabled) {
            return true;
        }

        $lock = $this->lockFactory->create(static::EXPORTS_FILE);
        $lock->assertExclusiveAllowWait(1);

        $lines = explode("\n", $this->filesystem->fileGetContents(static::EXPORTS_FILE));

        $keepLines = [];
        foreach ($lines as $line) {
            if ($this->isExportLine($line, $path)) {
                continue; // skips over the line to be deleted
            }

            $keepLines[] = $line;
        }

        $this->filesystem->filePutContents(static::EXPORTS_FILE, implode("\n", $keepLines));
        $lock->unlock();

        $this->applyChanges();

        return !$this->isEnabled($path);
    }

    /**
     * Gets the line number in exports file where the directory path is present.
     * The file pointer is at EOF after calling this method.
     *
     * @param string $path path of the directory to check for in NFS exports
     * @param string $host optional host the directory is exported to
     * @return bool true if exported (enabled), otherwise false (disabled)
     */
    public function isEnabled(string $path, string $host = ''): bool
    {
        $lock = $this->lockFactory->create(static::EXPORTS_FILE);
        $lock->assertSharedAllowWait(1);

        $lines = explode("\n", $this->filesystem->fileGetContents(static::EXPORTS_FILE));

        $lock->unlock();

        foreach ($lines as $line) {
            if ($this->isExportLine($line, $path, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether $line is an export of $path and $host
     */
    private function isExportLine(string $line, string $path, string $host = ''): bool
    {
        if ($host) {
            return preg_match("#$path\s+$host\(*#", $line);
        }
        return preg_match("#$path\s+#", $line);
    }

    /**
     * Prune invalid lines from the NFS Exports file, to prevent it from failing to export any shares.
     */
    private function pruneInvalidExports()
    {
        // Get an exclusive lock to the NFS Export file
        $lock = $this->lockFactory->create(static::EXPORTS_FILE);
        $lock->assertExclusiveAllowWait(1);

        // Grab all of the lines in the file
        $lines = explode("\n", $this->filesystem->fileGetContents(static::EXPORTS_FILE));

        // Search through the file to see if any invalid lines need to be removed
        $linesRemoved = false;
        $keepLines = [];
        foreach ($lines as $line) {
            if (!$this->isLineValid($line)) {
                $linesRemoved = true;
                $this->logger->warning('NFS0003 Trimming invalid line from NFS Exports', [
                    'line' => $line
                ]);
                continue;
            }

            $keepLines[] = $line;
        }

        // If any lines were removed, re-write the new file using only the kept lines
        if ($linesRemoved) {
            $this->filesystem->filePutContents(static::EXPORTS_FILE, implode("\n", $keepLines));
        }

        // Release the lock
        $lock->unlock();
    }

    /**
     * Checks a line in the /etc/exports file to ensure that it is valid.
     */
    private function isLineValid($line): bool
    {
        $line = trim($line);
        // Blank lines and those starting with '#' (comments) are valid
        if ((strlen($line) === 0) || (substr($line, 0, 1) === '#')) {
            return true;
        }

        // Extract the exported path from the remaining lines. These must be in the following format:
        //  '<export> <host1>(<options>) <hostN>(<options>)...'
        $tokens =  preg_split('/\s+/', $line);
        if (is_array($tokens) && (count($tokens) >= 2)) {
            // Ensure that the given path exists
            return $this->filesystem->exists($tokens[0]);
        }
        return false;
    }

    private function applyChanges()
    {
        // Attempt to sync the file system, to make sure any exported paths exist before we validate the file
        try {
            $this->processFactory->get(['sync'])->setTimeout(60)->mustRun();
        } catch (Throwable $throwable) {
            $this->logger->warning('NFS0004 sync process timed out', [
                'error' => $throwable->getMessage()
            ]);
        }

        try {
            $this->processFactory->get(['exportfs', '-ra'])->mustRun();
        } catch (Throwable $throwable) {
            $this->logger->warning('NFS0005 Failed to export NFS Shares. Pruning invalid entries and retrying.', [
                'error' => $throwable->getMessage()
            ]);

            // Usually a failure here is because the file has ended up with invalid lines in it. As a
            // failsafe, attempt to detect and remove those invalid lines, and try again
            $this->pruneInvalidExports();
            $this->processFactory->get(['exportfs', '-ra'])->mustRun();
        }
    }
}
