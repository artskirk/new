<?php

namespace Datto\Log;

use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Class LogArchiver
 *
 * This class implements logic to archive compressed rotated log files.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class LogArchiver
{
    const PROCESS_TIMEOUT_SECONDS = DateTimeService::SECONDS_PER_HOUR;

    private Filesystem $filesystem;
    private ProcessFactory $processFactory;

    /** @var array $compressedFileExtensions Array containing common compressed file extensions. */
    private $compressedFileExtensions;

    public function __construct(
        Filesystem $filesystem = null,
        ProcessFactory $processFactory = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->processFactory = $processFactory ?? new ProcessFactory();
        $this->compressedFileExtensions = array('gz', 'bz2', 'zip', 'tgz');
    }

    /**
     * Archives rotated compressed logs to the destination directory.
     *
     * Notes:
     * (1) This method extracts the log file paths from the logrotate configs
     * (2) The source directory tree is maintained in the destination directory
     * (3) Old logs are removed from the destination directory
     *
     * @param string $destinationBaseDir Path to the base directory where logs
     * will be archived to.
     *
     * be deleted from the destination directory, or logs cannot be moved
     * from the source to the destination directory.
     */
    public function archive($destinationBaseDir)
    {
        $logFiles = $this->getLogFiles();
        foreach ($logFiles as $logFile) {
            // Compress any uncompressed archived file of this log file
            $this->compressArchivedLogs($logFile);

            // Create destination directory if it doesn't already exist
            $destinationDir = $destinationBaseDir . dirname($logFile);
            if (!$this->filesystem->exists($destinationDir) &&
                !$this->filesystem->mkdir($destinationDir, true, 0766)
            ) {
                $message = sprintf(
                    "Error: Could not create destination directory '%s'.",
                    $destinationDir
                );
                throw new Exception($message);
            }

            // Remove old files from the destination
            $this->deleteOldLogs($logFile, $destinationDir);

            // Move compressed archived files to the destination
            $this->moveArchivedCompressedLogs($logFile, $destinationDir);
        }
    }

    /**
     * Returns an array containing all the log files the archives of which need
     * to be moved to /home/configBackup/. These log files are extracted from
     * the logrotate config files.
     *
     * @return array Array containing the log files.
     */
    private function getLogFiles()
    {
        // Create an array of all the logrotate config files
        $configFiles = $this->filesystem->glob("/etc/logrotate.d/*");
        $configFiles[] = '/etc/logrotate.conf';

        // Extract log file paths from each config file
        $logFiles = array();
        $pattern = '/^"?\//';
        foreach ($configFiles as $configFile) {
            $lines = explode("\n", $this->filesystem->fileGetContents($configFile));
            $items = preg_grep($pattern, $lines);
            foreach ($items as $item) {
                // Each item can itself be a space-separated list of paths,
                // possibly ending with a '{' character, so go through
                // each path in the list.
                $item = trim($item);
                $paths = explode(' ', $item);
                foreach ($paths as $path) {
                    $path = trim($path, " \t\n{\"");

                    // If path is empty or a '{' character, skip to the next
                    // iteration
                    if (empty($path) || $path === '{') {
                        continue;
                    }

                    // A path can be a pattern, so we need to glob the pattern
                    // to fetch all the files
                    $files = $this->filesystem->glob($path);
                    foreach ($files as $file) {
                        $logFiles[] = $file;
                    }
                }
            }
        }

        return $logFiles;
    }

    /**
     * Compresses the given active log file's archived logs if they are not
     * already compressed.
     *
     * @param string $activeLogFile Path to the active log file the archives of
     * which we need to compress.
     * fails.
     */
    private function compressArchivedLogs($activeLogFile)
    {
        $archivedLogs = $this->filesystem->glob("$activeLogFile.*");
        foreach ($archivedLogs as $archivedLog) {
            // Skip if the archived log is already compressed
            $extension = pathinfo($archivedLog, PATHINFO_EXTENSION);
            if (in_array($extension, $this->compressedFileExtensions)) {
                continue;
            }

            // If a compressed file exists for this file, which should be rare,
            // rename that compressed file. For example, if 'somefile.1' and
            // 'somefile.1.gz' both exist, then rename 'somefile.1.gz' to
            // 'somefile.1-old-<random-number>.gz'.
            $randomNumber = rand(1, 999);
            $newFilename = "$archivedLog-old-$randomNumber.gz";
            if ($this->filesystem->exists("$archivedLog.gz") &&
                $this->filesystem->rename("$archivedLog.gz", $newFilename) === false
            ) {
                throw new Exception("Error: Could not rename $archivedLog.gz to $newFilename.");
            }

            // Use gzip utility to compress the archived log
            $process = $this->processFactory->get(['gzip', $archivedLog])
                ->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
            $process->mustRun();
        }
    }

    /**
     * Deletes old archives of the given active log file from the destination
     * directory.
     *
     * @param string $activeLogFile Path to the active log file the old archives
     * of which need to be deleted from the given destination directory.
     * @param string $destinationDir Path to the destination directory from
     * where old logs need to be deleted.
     */
    private function deleteOldLogs($activeLogFile, $destinationDir)
    {
        // If there are no old archived logs to delete, just return
        $activeLogFileName = basename($activeLogFile);
        $oldArchivedLogs = $this->filesystem->glob("$destinationDir/$activeLogFileName.*");
        if (empty($activeLogFileName) ||
            empty($destinationDir) ||
            count($oldArchivedLogs) === 0
        ) {
            return;
        }

        // Delete old archived logs from the destination directory
        foreach ($oldArchivedLogs as $oldArchivedLog) {
            if ($this->filesystem->unlink($oldArchivedLog) === false) {
                throw new Exception("Error: Could not delete $oldArchivedLog.");
            }
        }
    }

    /**
     * Moves archived compressed logs from the source directory to the given
     * destination directory.
     *
     * @param string $activeLogFile Path to the active log file the archived
     * files of which we need to move to the destination directory.
     * @param string $destinationDir Path to the destination directory to where
     * we need to move the archived files.
     */
    private function moveArchivedCompressedLogs($activeLogFile, $destinationDir)
    {
        // If there are no compressed archived files to move or the destination
        // directory does not exist, just return
        if (count($this->filesystem->glob("$activeLogFile.*")) === 0 || !$this->filesystem->isDir($destinationDir)) {
            return;
        }

        // Move compressed archived files
        $process = $this->processFactory->getFromShellCommandLine('rsync -av --remove-source-files "${:LOG_FILE}".* "${:DESTINATION_DIR}"');
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);

        $process->mustRun(null, ['LOG_FILE' => $activeLogFile, 'DESTINATION_DIR' => $destinationDir]);
    }
}
