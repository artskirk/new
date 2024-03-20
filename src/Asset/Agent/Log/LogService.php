<?php

namespace Datto\Asset\Agent\Log;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Backup\BackupException;
use Datto\Log\AssetRecord;
use Datto\Log\Formatter\AssetFormatter;
use Datto\Utility\File\Tail;

/**
 * Retrieves Asset log information
 *
 * @author John Roland <jroland@datto.com>
 */
class LogService
{
    // Legacy log codes that indicate a failure. These do not have letter prefixes.
    const FAIL_CODES = [4, 7, 11, 13, 14, 17, 18, 22, 104, 201, 450, 608, 612, 620, 621, 630, 631];

    const DEFAULT_LINES = 1000;
    const VDDK_LOG_FILE = '/var/log/vddk-fuse.log';

    /** @var RetrieverFactory */
    private $logRetrieverFactory;

    /** @var Tail */
    private $tail;

    /**
     * @param RetrieverFactory|null $logRetrieverFactory
     * @param Tail|null $tail
     */
    public function __construct(
        RetrieverFactory $logRetrieverFactory = null,
        Tail $tail = null
    ) {
        $this->logRetrieverFactory = $logRetrieverFactory ?: new RetrieverFactory();
        $this->tail = $tail ?: new Tail();
    }

    /**
     * Retrieves Agent log information
     *
     * @param Agent $agent
     * @param int|null $lineCount
     * @param int|null $severity
     * @return AgentLog[]
     */
    public function get(Agent $agent, int $lineCount = null, int $severity = null)
    {
        $logRetriever = $this->logRetrieverFactory->create($agent);
        return $logRetriever->get($lineCount, $severity);
    }

    /**
     * Generate an exception by parsing the backup logs on the agent.
     *
     * @param Agent $agent
     * @param int $lineCount
     * @param int $severity
     * @param string $defaultMessage
     * @param array $context
     * @return BackupException
     */
    public function generateException(
        Agent $agent,
        int $lineCount,
        int $severity,
        string $defaultMessage,
        array $context = []
    ): BackupException {
        $agentLogs = $this->get($agent, $lineCount, $severity);
        foreach ($agentLogs as $agentLog) {
            if (strpos($agentLog->getMessage(), '-109') !== false) {
                continue; // Skip -109 (communication issue) log lines, the real error will be the next line
            }

            $message = trim($agentLog->getMessage());
            if (preg_match(
                "/^stcx.NotFound: Image file [A-Za-z0-9\s\/\-_?.:\\\\]+\.d[ae]tto is not found or not accessible.$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_DATTO_IMAGE_NOT_FOUND);
            } elseif (preg_match(
                "/^Final error \(-2 The system cannot find the file specified.\)$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_FILE_NOT_FOUND);
            } elseif (preg_match(
                "/^stcx.SnapshotError: Snapshot returned error code -121 for STC.$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_NETWORK_COMMUNICATION);
            } elseif (preg_match(
                "/^stcx.SnapshotError: Snapshot returned error code -1450 for STC.$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_INSUFFICIENT_RESOURCES);
            } elseif (preg_match(
                "/^Final error \(-31 A device attached to the system is not functioning.\)$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_FINAL_ERROR);
            } elseif (preg_match(
                "/^Fatal I\/O error [A-Za-z0-9\s\/\-_?.:\\\\]+ on read \(-31 A device attached to the system is not functioning.\)$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_READ_ERROR);
            } elseif (preg_match(
                "/^Fatal I\/O error [A-Za-z0-9\s\/\-_?.:\\\\]+ on write \(-31 A device attached to the system is not functioning.\)$/",
                $message
            )) {
                return new BackupException($message, BackupException::STC_WRITE_ERROR);
            } elseif (preg_match(
                "/^[A-Za-z0-9\s\/\-_?.:\\\\ <>]*\[Errno 4\] Interrupted function call[A-Za-z0-9\s\/\-_?.:\\\\ <>']*$/",
                $message
            )) {
                return new BackupException($message, BackupException::ERRNO_4_INTERRUPTED_FUNCTION);
            } elseif (preg_match(
                "/^[A-Za-z0-9\s\/\-_?.:\\\\ <>]*\[Errno 13\] Permission denied[A-Za-z0-9\s\/\-_?.:\\\\ <>']*$/",
                $message
            )) {
                return new BackupException($message, BackupException::ERRNO_13_PERMISSION_DENIED);
            }
        }

        if (($context['errorCode'] ?? 0) > 0) {
            return new BackupException($defaultMessage, $context['errorCode'], null, $context);
        }

        return new BackupException($defaultMessage);
    }

    /**
     * Retrieves asset keyfile logs in a more structured format
     *
     * @param Asset $asset The asset to retrieve the logs
     * @param int $limit The max number of lines to retrieve from the end of the log file
     * @return AssetRecord[] log lines where each line is an array containing 'time', 'code', 'message'
     * and 'important'
     */
    public function getLocalDescending(Asset $asset, int $limit = self::DEFAULT_LINES): array
    {
        $logs = $this->readLocalLogs($asset, $limit);
        /** @var AssetRecord[] $records */
        $records = array_reverse(AssetFormatter::parse($logs));

        foreach ($records as $record) {
            $important = in_array($record->getCode(), self::FAIL_CODES);
            $record->setImportant($important);
        }

        return $records;
    }

    /**
     * Retrieves the vddk logs for the system
     *
     * @param int $limit
     * @return string[] Log lines
     */
    public function readVddkLogs(int $limit = self::DEFAULT_LINES): array
    {
        $logs = $this->tail->getLines(self::VDDK_LOG_FILE, $limit);

        return explode(PHP_EOL, $logs);
    }

    /**
     * Retrieves asset keyfile logs
     *
     * @param Asset $asset The asset to retrieve the logs for
     * @param int $limit The max number of lines to retrieve from the end of the log file
     * @return string
     */
    private function readLocalLogs(Asset $asset, int $limit = self::DEFAULT_LINES): string
    {
        $logPath = Agent::KEYBASE . $asset->getKeyName() . '.log';

        return $this->tail->getLines($logPath, $limit);
    }
}
