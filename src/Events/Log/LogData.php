<?php

namespace Datto\Events\Log;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\RemoveNullProperties;
use Monolog\Logger;

/**
 * Details about the log event
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogData extends AbstractEventNode
{
    use RemoveNullProperties;

    /** @var int Numeric level of the log (100, 200, 300, etc...) */
    protected int $logLevel;

    /** @var string String level of the log (Error, Warning, Info, Debug) */
    protected string $level;

    /** @var string unique string associated with a particular log message */
    protected string $logCode;

    public function __construct(int $logLevel, string $logCode)
    {
        $this->logLevel = $logLevel;
        $this->level = Logger::getLevelName($logLevel);
        $this->logCode = $logCode;
    }
}
