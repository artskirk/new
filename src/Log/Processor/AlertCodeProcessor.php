<?php

namespace Datto\Log\Processor;

use Datto\Alert\AlertCodes;
use Datto\Log\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;
use Monolog\Logger;

/**
 * Adds contextual alert code fields to log records.
 * Required if using an Asset* handler or formatter.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class AlertCodeProcessor implements ProcessorInterface
{
    const DEFAULT_ALERT_PREFIX = 'XXX';
    const DEFAULT_ALERT_SEVERITY = 0;
    const DEFAULT_ALERT_CATEGORY = 'Event';

    /** @var AlertCodes */
    private $alertCodes;

    public function __construct(AlertCodes $alertCodes)
    {
        $this->alertCodes = $alertCodes;
    }

    /**
     * Processes the given record.
     *
     * @param array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        $logRecord = new LogRecord($record);
        try {
            if (preg_match('/^([A-Za-z]{3}[0-9]{4})\s(.*)$/s', $logRecord->getMessage(), $match)) {
                $logRecord->setMessage($match[2]);
                $logRecord->setAlertCode($match[1]);
                $logRecord->setAlertSeverity($this->alertCodes->check($match[1]));
                $logRecord->setAlertCategory($this->alertCodes->getCategory($match[1]) ?? '');
            } else {
                $resultString = str_pad((string)$logRecord->getLevel(), 4, '0', STR_PAD_LEFT);
                $logRecord->setAlertCode(static::DEFAULT_ALERT_PREFIX . $resultString);
                $logRecord->setAlertSeverity(static::DEFAULT_ALERT_SEVERITY);
                $logRecord->setAlertCategory(static::DEFAULT_ALERT_CATEGORY);
            }
        } catch (Throwable $e) {
            // don't allow exception here to prevent logging
        }

        return $logRecord->toArray();
    }
}
