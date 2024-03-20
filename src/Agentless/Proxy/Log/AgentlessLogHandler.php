<?php

namespace Datto\Agentless\Proxy\Log;

use Datto\Agentless\Proxy\AgentlessSessionService;
use Datto\Log\LogRecord;
use Datto\Utility\File\LockFactory;
use Datto\Common\Utility\Filesystem;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;

/**
 * Writes log entries to the specified session log file in a structured way.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessLogHandler extends AbstractHandler
{
    private const SEVERITY_DEBUG = 1;
    private const SEVERITY_INFO = 2;
    private const SEVERITY_WARNING = 3;
    private const SEVERITY_ERROR = 4;
    private const SEVERITY_CRITICAL = 5;
    private const SEVERITY_DEFAULT = self::SEVERITY_WARNING;

    private const MKDIR_MODE = 0777;

    /** @var Filesystem */
    private $filesystem;

    /** @var LockFactory */
    private $lockFactory;

    public function __construct(
        Filesystem $filesystem,
        LockFactory $lockFactory
    ) {
        parent::__construct(Logger::DEBUG, true);

        $this->filesystem = $filesystem;
        $this->lockFactory = $lockFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $logRecord = new LogRecord($record);

        if (!$logRecord->hasSessionIdName()) {
            return false;
        }

        $sessionIdName = $logRecord->getSessionIdName();

        $sessionPath = sprintf(AgentlessSessionService::SESSION_PATH_FORMAT, $sessionIdName);
        $this->filesystem->mkdirIfNotExists($sessionPath, true, self::MKDIR_MODE);

        $logPath = sprintf(
            AgentlessSessionService::SESSION_LOG_PATH_FORMAT,
            $sessionIdName
        );

        $lock = $this->lockFactory->getProcessScopedLock($logPath);
        $lock->exclusive();

        $lastLog = $this->getLastEntry($logPath);
        $newIndex = ($lastLog['index'] ?? 0) + 1;

        $record = $this->format($record, $newIndex);
        $this->filesystem->filePutContents($logPath, $record, FILE_APPEND);

        $lock->unlock();

        return false === $this->bubble;
    }

    /**
     * Formats a log record.
     *
     * @param array $record A record to format
     * @param int $newIndex
     * @return string The formatted record
     */
    public function format(array $record, int $newIndex)
    {
        $severity = self::SEVERITY_DEFAULT;

        $level = $record['level'];
        switch ($level) {
            case 100:
                $severity = self::SEVERITY_DEBUG;
                break;
            case 200:
                $severity = self::SEVERITY_INFO;
                break;
            case 300:
                $severity = self::SEVERITY_WARNING;
                break;
            case 400:
                $severity = self::SEVERITY_ERROR;
                break;
            case 500:
                $severity = self::SEVERITY_CRITICAL;
                break;
        }

        $output = [
            'index' => $newIndex,
            'severity' => $severity,
            'timestamp' => date_timestamp_get($record['datetime']),
            'message' => $record['message']
        ];

        return json_encode($output) . PHP_EOL;
    }

    /**
     * @param string $logPath
     * @return array
     */
    private function getLastEntry(string $logPath)
    {
        $rawLastEntry = $this->getLastLine($logPath);

        return json_decode($rawLastEntry, true);
    }

    /**
     * @param string $logPath
     * @return string
     */
    private function getLastLine(string $logPath)
    {
        $line = '';

        $file = $this->filesystem->open($logPath, 'r');
        $cursor = -1;

        $this->filesystem->seek($file, $cursor, SEEK_END);
        $char = fgetc($file);

        // Trim trailing newline chars of the file
        while ($char === "\n" || $char === "\r") {
            $this->filesystem->seek($file, $cursor--, SEEK_END);
            $char = fgetc($file);
        }

        // Read until the start of file or first newline char
        while ($char !== false && $char !== "\n" && $char !== "\r") {
            // Prepend the new char
            $line = $char . $line;
            fseek($file, $cursor--, SEEK_END);
            $char = fgetc($file);
        }

        $this->filesystem->close($file);

        return $line;
    }
}
