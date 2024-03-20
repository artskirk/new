<?php

namespace Datto\Log\Handler;

use Datto\Alert\AlertCodes;
use Datto\Log\Formatter\CefFormatter;
use Datto\Log\LogRecord;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Send logs to a remote machine in the Common Event Format (CEF).
 * Rsyslog is used for the actual transfer to the remote machine.
 *
 * Logs with a level _lower_ than INFO will be ignored.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AssetRemoteHandler extends AbstractProcessingHandler
{
    const SYSLOG_IDENTITY = 'datto.audit';

    /** @var CefFormatter */
    private $cefFormatter;

    public function __construct(
        CefFormatter $cefFormatter,
        string $loggerLevel
    ) {
        parent::__construct($loggerLevel, true);

        $this->cefFormatter = $cefFormatter;
    }

    /**
     * Writes to syslog. The device should already
     * have rsyslog configured to transport the message over the network.
     *
     * @param array $record
     */
    protected function write(array $record): void
    {
        $logRecord = new LogRecord($record);

        if (!$logRecord->hasCefExtensions()) {
            return;
        }

        $priority = $this->getPriority($logRecord);

        // todo: use SyslogHandler instead???
        // Send the formatted message to syslog
        openlog(static::SYSLOG_IDENTITY, LOG_NDELAY | LOG_PID, LOG_LOCAL3);
        syslog($priority, $logRecord->getFormatted());
        closelog();
    }

    /**
     * Please do not change this or set a new formatter.
     *
     * @return FormatterInterface
     */
    protected function getDefaultFormatter()
    {
        return $this->cefFormatter;
    }

    /**
     * Get the priority based on the alert severity
     *
     * @param LogRecord $logRecord
     * @return int
     */
    private function getPriority(LogRecord $logRecord): int
    {
        $alertSeverity = $logRecord->getAlertSeverity();

        // Determine syslog priority
        $priority = LOG_INFO;
        if ($alertSeverity& AlertCodes::CRITICAL) {
            $priority = LOG_CRIT;
        } elseif ($alertSeverity & AlertCodes::WARNING) {
            $priority = LOG_WARNING;
        }

        return $priority;
    }
}
