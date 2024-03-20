<?php

namespace Datto\Log\Formatter;

use Datto\Log\LogRecord;

/**
 * Formats a log record into a pretty colored CLI format.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Justin Giacobbi (justin@datto.com)
 */
class ConsoleFormatter extends AbstractFormatter
{
    /** @var int */
    private $backtraceLevel;

    public function __construct(
        int $backtraceLevel = 4
    ) {
        parent::__construct();

        $this->backtraceLevel = $backtraceLevel;
    }

    /**
     * Formats a log record for console output.
     *
     * @param array $record
     * @return string
     */
    public function format(array $record)
    {
        $record = $this->normalize($record);
        $backtraceItem = $this->getBacktraceItem($this->backtraceLevel);

        $logRecord = new LogRecord($record);

        $preface = sprintf(
            '%s %s %s %s %s %s ',
            $this->formatDate($logRecord->getDateTime()),
            $this->formatAlertCode($logRecord->getAlertCode()),
            $this->formatChannel($logRecord->getChannel()),
            $this->formatContextId($logRecord->getContextId()),
            $this->formatBacktrace($backtraceItem["file"], $backtraceItem["line"]),
            $this->formatLevel($logRecord->getLevel())
        );
        $prefaceLength = $this->prefaceLength($logRecord);

        $message = $this->formatMessage($logRecord->getMessage(), $prefaceLength);
        $context = $this->formatContext($record, $prefaceLength);

        $output = $preface . $message;
        if (!empty($context)) {
            $output .= PHP_EOL . $context;
        }

        return $output . PHP_EOL;
    }
}
