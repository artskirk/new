<?php

namespace Datto\Reporting\Backup;

use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;

/**
 * Handles the reading and writing of backup report json files
 *
 * Sample Usage:
 *      $reportContext = new BackupReportContext($agentKeyName);
 *      $backupReportManager->startBackupReport($reportContext, $time, $type);
 *      while($attempting) {
 *          $backupReportManager->beginBackupAttempt($reportContext);
 *          //Attempt the backup...
 *          $backupReportManager->endFailed/SuccessfulBackupAttempt($reportContext);
 *      }
 *      $backupReportManager->finishBackupReport($reportContext);
 *
 */
class BackupReportManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const KEY_CURRENT_BACKUP_REPORTS = 'currentBackupReport';
    public const KEY_ALL_BACKUPS_REPORTS = 'backupReports';

    private Filesystem $filesystem;
    private DateTimeService $dateTimeService;
    private AgentConfigFactory $agentConfigFactory;

    public function __construct(
        Filesystem $filesystem,
        DateTimeService $dateTimeService,
        AgentConfigFactory $agentConfigFactory
    ) {
        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
        $this->agentConfigFactory = $agentConfigFactory;
    }

    /**
     * Reads JSON information from the current backup file.
     * Starts a backup attempt with the current time.
     * @param BackupReportContext $context
     * @param ?int $time Scheduled time for backup
     * @param ?string $type Type of backup (forced|scheduled)
     */
    public function startBackupReport(BackupReportContext $context, int $time = null, string $type = null)
    {
        $currentReportFile = $this->getCurrentReportsFilePath($context);
        $context->setBackupReport($this->readLatest($currentReportFile));

        if ($context->getBackupReport() === null) {
            $context->setBackupReport(new BackupReport($time, $type));
            $this->writeFile($currentReportFile, [$context->getBackupReport()]);
        }
    }

    /**
     * Begins a new backup attempt
     * @param BackupReportContext $context
     */
    public function beginBackupAttempt(BackupReportContext $context)
    {
        $status = new BackupAttemptStatus($this->dateTimeService->getTime());
        $context->setBackupAttemptStatus($status);
    }

    /**
     * Ends the backup attempt with a false success, sets the snap code.
     * @param BackupReportContext $context
     * @param string $code The snap code related to the backup
     * @param string $message Message describing how the backup failed
     */
    public function endFailedBackupAttempt(BackupReportContext $context, string $code, string $message)
    {
        $this->endBackupAttempt($context, false, $code, $message);
    }

    /**
     * Ends the backup attempt with a true success, sets the snap code.
     * @param BackupReportContext $context
     * @param string $code The snap code related to the backup
     */
    public function endSuccessfulBackupAttempt(BackupReportContext $context, string $code)
    {
        $this->endBackupAttempt($context, true, $code);
    }

    /**
     * Writes the final backup object to the complete backup log file.
     * @param BackupReportContext $context
     */
    public function finishBackupReport(BackupReportContext $context)
    {
        $this->appendFile($this->getAllReportsFilePath($context), $context->getBackupReport());
        $this->clearFile($this->getCurrentReportsFilePath($context));
    }

    /**
     * Reads a report file for an agent.
     * @param string $keyName The key for the agent to read reports
     * @return BackupReport[] Array of backup reports
     */
    public function readReports(string $keyName): array
    {
        $agentConfig = $this->agentConfigFactory->create($keyName);
        $reportFile = $agentConfig->getConfigFilePath(self::KEY_ALL_BACKUPS_REPORTS);
        return $this->readFromFile($reportFile);
    }

    /**
     * Removes backup reports for all agents on the device
     * @param int $retentionLimit The length of time in seconds to retain backups for. Default is 1 year.
     */
    public function removeOldBackupReportsFromAllAgents(int $retentionLimit = DateTimeService::SECONDS_PER_DAY * 366)
    {
        $keyNames = $this->agentConfigFactory->getAllKeyNames();
        foreach ($keyNames as $keyName) {
            try {
                $this->removeOldBackupReports($keyName, $retentionLimit);
            } catch (\Throwable $t) {
                $this->logger->error("ROB0001 Failed to remove old backup reports", ['keyName' => $keyName, 'exception' => $t]);
            }
        }
    }

    /**
     * Removes backup reports from the report file if they are older than the given retention time.
     * @param string $keyName
     * @param int $retentionTimeLimit The length of time in seconds to retain backups for. Default is 1 year.
     */
    public function removeOldBackupReports(
        string $keyName,
        int $retentionTimeLimit = DateTimeService::SECONDS_PER_DAY * 366
    ) {
        $agentConfig = $this->agentConfigFactory->create($keyName);
        $reportFile = $agentConfig->getConfigFilePath(self::KEY_ALL_BACKUPS_REPORTS);

        $backupArray = $this->readFromFile($reportFile);
        $currentTime = $this->dateTimeService->getTime();
        $newBackupArray = array_filter($backupArray, function ($backup) use ($retentionTimeLimit, $currentTime) {
            return ($currentTime - $backup->getScheduledTime()) < $retentionTimeLimit;
        });
        if (count($newBackupArray) < count($backupArray)) {
            $this->writeFile($reportFile, $newBackupArray);
        }
    }

    /**
     * Writes to a new report file, or overwrites existing.
     * @param string $reportFile The file name to read/write from
     * @param BackupReport[] $reports BackupReports to write
     */
    private function writeFile(string $reportFile, array $reports)
    {
        $this->clearFile($reportFile);
        foreach ($reports as $report) {
            $this->appendFile($reportFile, $report);
        }
    }

    /**
     * Reads the last report from a file.
     * @param string $reportFile The file name to read/write from
     * @return BackupReport|null
     */
    private function readLatest(string $reportFile): ?BackupReport
    {
        $reports = $this->readFromFile($reportFile);
        if (empty($reports)) {
            return null;
        }
        return end($reports);
    }

    /**
     * Appends a report to a new or existing file
     * @param string $reportFile The file name to read/write from
     * @param BackupReport $report BackupReport to write
     */
    private function appendFile(string $reportFile, BackupReport $report)
    {
        $rawJson = json_encode($report->toArray());
        $this->filesystem->filePutContents($reportFile, $rawJson . "\n", FILE_APPEND);
    }

    /**
     * Removes the report file if it exists.
     */
    private function clearFile($reportFile)
    {
        $this->filesystem->unlinkIfExists($reportFile);
    }

    /**
     * Converts a raw backup report (JSON) into a BackupReport object.
     *
     * @param string $rawJson
     * @return BackupReport|null
     */
    private function jsonToBackupReport(string $rawJson): ?BackupReport
    {
        $data = json_decode($rawJson, true);
        if (empty($data)) {
            return null;
        }
        $backup = new BackupReport($data['scheduledTime'], $data['type']);

        foreach ($data['attempts'] as $attemptArray) {
            $attempt = new BackupAttemptStatus($attemptArray['time']);

            // Recording the message was added after the first release
            if (isset($attemptArray['message'])) {
                $attempt->setMessage($attemptArray['message']);
            }

            $attempt->setCode($attemptArray['code']);
            $attempt->finish($attemptArray['success']);
            $backup->addAttempt($attempt);
        }
        $backup->setCompletedTime($data['completedTime']);
        $backup->setSuccess($data['success']);

        return $backup;
    }

    /**
     * Appends the current backup attempt and writes to the current report file
     * @param BackupReportContext $context
     * @param bool $success
     * @param string $code
     * @param ?string $message
     */
    private function endBackupAttempt(BackupReportContext $context, bool $success, string $code, string $message = null)
    {
        $context->getBackupAttemptStatus()->setCode($code);
        $context->getBackupAttemptStatus()->setMessage($message);
        $context->getBackupAttemptStatus()->finish($success);
        $context->getBackupReport()->addAttempt($context->getBackupAttemptStatus());
        $context->getBackupReport()->setCompletedTime($this->dateTimeService->getTime());
        $currentReportFile = $this->getCurrentReportsFilePath($context);
        $this->writeFile($currentReportFile, [$context->getBackupReport()]);
    }

    private function getCurrentReportsFilePath(BackupReportContext $context): string
    {
        $agentConfig = $this->agentConfigFactory->create($context->getKeyName());
        return $agentConfig->getConfigFilePath(self::KEY_CURRENT_BACKUP_REPORTS);
    }

    private function getAllReportsFilePath(BackupReportContext $context): string
    {
        $agentConfig = $this->agentConfigFactory->create($context->getKeyName());
        return $agentConfig->getConfigFilePath(self::KEY_ALL_BACKUPS_REPORTS);
    }

    /**
     * Reads a report file.
     * @param string $reportFile The file name to read/write from
     * @return BackupReport[] Array of backup reports
     */
    private function readFromFile(string $reportFile): array
    {
        if (!$this->filesystem->exists($reportFile)) {
            return [];
        }

        $backupArray = [];

        $f = $this->filesystem->open($reportFile, 'r');

        while ($rawJson = $this->filesystem->getLine($f)) {
            $backup = $this->jsonToBackupReport($rawJson);
            if ($backup === null) {
                continue;
            }
            $backupArray[] = $backup;
        }

        $this->filesystem->close($f);
        return $backupArray;
    }
}
