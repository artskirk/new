<?php

namespace Datto\Log\Formatter;

use Datto\Log\LogRecord;
use Monolog\Formatter\FormatterInterface;

/**
 * Formats a log record into the Common Event Format (CEF)
 * used in the 'Remote Logging' feature.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class CefFormatter implements FormatterInterface
{
    /**
     * Formats a log record.
     *
     * @param array $record A record to format
     * @return mixed The formatted record
     */
    public function format(array $record)
    {
        $logRecord = new LogRecord($record);

        if (!$logRecord->hasCefExtensions()) {
            return '';
        }

        $extensions = $logRecord->getCefExtensions();

        // Format the extensions by making them key=value
        $formattedExtensions = [];
        foreach ($extensions as $extensionKey => $extensionValue) {
            $formattedExtensions[] = $extensionKey . '=' . $extensionValue;
        }

        $cef = [];
        $cef[] = 'CEF:0';
        $cef[] = 'Datto';
        $cef[] = $logRecord->getDeviceModel();
        $cef[] = $logRecord->getPackageVersion();
        $cef[] = $logRecord->getAlertCode();
        $cef[] = $logRecord->getMessage();
        $cef[] = $logRecord->getAlertSeverity();
        $cef[] = implode(' ', $formattedExtensions);

        return implode('|', $cef);
    }

    /**
     * @inheritDoc
     * @return mixed
     */
    public function formatBatch(array $records)
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }
        return $message;
    }
}
