<?php

namespace Datto\Log\Formatter;

use DateTime;
use Datto\Log\LoggerHelperTrait;
use Datto\Log\LogRecord;
use Monolog\Formatter\JsonFormatter;
use Monolog\Logger;

/**
 * Formats a log record in a json format.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceFormatter extends JsonFormatter
{
    use LoggerHelperTrait;

    /**
     * @inheritdoc
     * @return mixed
     */
    public function format(array $record)
    {
        return parent::format($this->formatRecord($record));
    }

    /**
     * @inheritdoc
     */
    protected function formatBatchJson(array $records): string
    {
        return parent::formatBatchJson(array_map(array($this, 'formatRecord'), $records));
    }

    /**
     * Formats the passed record to be a Datto log
     *
     * @param array $record
     *
     * @return array
     */
    protected function formatRecord(array $record)
    {
        $record = $this->normalize($record);

        $logRecord = new LogRecord($record);

        // microtime needs to be explicitly padded to 6 decimal places because, since it's a decimal, trailing 0s are
        // omitted which can cause createFromFormat to return false instead of a DateTime.
        $now = DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true)));

        $context = $this->getContextWithHiddenMetadataRemoved($logRecord->getContext());

        # Message and context come first so it is easier to read the logs in report portal when debugging systemtests
        $formatted = [
            'message' => $logRecord->getMessage(),
            'context' => $context,
            'channel' => $logRecord->getChannel(),
            'contextId' => $logRecord->getContextId(),
            'level' => Logger::getLevelName($logRecord->getLevel()),
            'logCode' => $logRecord->getAlertCode(),
            'user' => $logRecord->getUser(),
            '@timestamp' => $now->format(DateTime::RFC3339_EXTENDED)
        ];


        if (!$context) {
            unset($formatted['context']);
        }

        return $formatted;
    }
}
