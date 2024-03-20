<?php

namespace Datto\Log\Processor;

use Datto\Asset\UuidGenerator;
use Datto\Log\LogRecord;

/**
 * Adds randomly generated, unique contextId to log record.
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class ContextIdProcessor
{
    const ALGORITHM = 'crc32';

    /** @var string */
    private $contextId;

    public function __construct(UuidGenerator $uuidGenerator)
    {
        $this->contextId = hash(self::ALGORITHM, $uuidGenerator->get());
    }

    /**
     * @param array
     *
     * @return array
     */
    public function __invoke($record)
    {
        $logRecord = new LogRecord($record);
        $logRecord->setContextId($this->contextId);

        return $logRecord->toArray();
    }

    /**
     * @return string
     */
    public function getContextId() : string
    {
        return $this->contextId;
    }
}
