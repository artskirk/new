<?php

namespace Datto\Log\Processor;

use Datto\Log\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Updates the record's context
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ContextProcessor implements ProcessorInterface
{
    /** @var array */
    private $globalContext = [];

    public function removeFromGlobalContext(string $key)
    {
        if (array_key_exists($key, $this->globalContext)) {
            unset($this->globalContext[$key]);
        }
    }

    public function updateGlobalContext(array $globalContext)
    {
        $this->globalContext = array_merge($this->globalContext, $globalContext);
    }

    /**
     * Processes the given record.
     *
     * @param array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        $this->convertException($record);

        $logRecord = new LogRecord($record);
        $logRecord->addToContext($this->globalContext);

        return $logRecord->toArray();
    }

    /**
     * Replaces an exception object in the context with its string representation
     */
    private function convertException(array &$record): void
    {
        $exception = $record['context']['exception'] ?? null;

        if ($exception instanceof Throwable) {
            unset($record['context']['exception']);
            $record['context']['error'] = $exception->getMessage();
            $record['context']['errorCode'] = $exception->getCode();
            $record['context']['stackTrace'] = $exception->getTraceAsString();
        }
    }
}
