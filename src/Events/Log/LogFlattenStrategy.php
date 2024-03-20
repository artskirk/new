<?php
namespace Datto\Events\Log;

use Datto\Events\PeriodDelimitedFlattenStrategy;

/**
 * The file defines the event key flattening strategy for log events.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class LogFlattenStrategy extends PeriodDelimitedFlattenStrategy
{
    protected function getDelimiter(string $prefix, string $key): string
    {
        return preg_match('/^context\\.logContext\\./', $prefix) ? '-' : '.';
    }
}
