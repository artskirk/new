<?php

namespace Datto\Log\Formatter;

use Datto\Log\AssetRecord;
use Datto\Log\LogRecord;
use Monolog\Formatter\FormatterInterface;
use Monolog\Logger;

/**
 * Formats a log record.
 * It's basically csv with colons.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 */
class AssetFormatter implements FormatterInterface
{
    const DATE_FORMAT = "M jS g:i:sa";

    /**
     * Formats a log record.
     *
     * @param array $record A record to format
     * @return string The formatted record
     */
    public function format(array $record)
    {
        $logRecord = new LogRecord($record);
        $recordContext = $logRecord->getContext();

        $lineParts = [];
        $lineParts[] = $logRecord->getDateTime()->getTimestamp();
        $lineParts[] = $logRecord->getAlertCode();
        $lineParts[] = $this->sanitizeMessage($recordContext['partnerAlertMessage'] ?? $logRecord->getMessage());
        $lineParts[] = $logRecord->getDateTime()->format(static::DATE_FORMAT);
        $lineParts[] = $logRecord->getUser();
        return implode(':', $lineParts) . PHP_EOL;
    }

    /**
     * Formats a set of log records.
     *
     * @param array $records
     * @return string
     */
    public function formatBatch(array $records)
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }
        return $message;
    }

    /**
     * @param string $formattedString
     * @return AssetRecord[]
     */
    public static function parse(string $formattedString): array
    {
        $records = [];

        /*
         * Example line:
         *      1528927780:SBK0620:Backup request ignored because agent is not running!:Jun 13th 6:09:40pm:(CLI)
         *
         * Matches:
         *      1) 1528927780
         *      2) SBK0620
         *      3) Backup request ignored because agent is not running!
         *      4) Jun 13th 6:09:40pm
         *      5) (CLI)
         */
        if (preg_match_all(
            '/^(?<epoch>[^:]+):(?<code>[^:]+):(?<message>[^:]+):(?<datetime>.+):(?<user>[^:]+)$/m',
            $formattedString,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $set) {
                $records[] = new AssetRecord((int)$set['epoch'], $set['code'], $set['message'], $set['user']);
            }
        }

        return $records;
    }

    /**
     * @param string $message
     * @return string
     */
    private function sanitizeMessage(string $message): string
    {
        $message = str_replace(':', '', $message);
        $message = str_replace("\n", '', $message);

        return $message;
    }
}
