<?php

namespace Datto\Events\Log;

use Datto\Events\AbstractEventNode;
use Datto\Events\Common\RemoveNullProperties;
use Datto\Events\EventContextInterface;
use Throwable;

/**
 * Class to implement the context node included in LogEvent
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogContext extends AbstractEventNode implements EventContextInterface
{
    use RemoveNullProperties;

    // Max field length in Elasticsearch is 32766 bytes.
    // We're limiting it to an even more conservative length here.
    const MAX_CONTEXT_FIELD_LENGTH = 2048;

    /** @var string log message presented to the user */
    protected $logMessage;

    /** @var string */
    protected $userName;

    /** @var string */
    protected $clientIp;

    /** @var array log message context key / values */
    protected $logContext;

    /** @var string deployment group label to facilitate log filtering */
    protected $deploymentGroup;

    public function __construct(
        string $logMessage,
        string $userName,
        string $clientIp,
        array $logContext = [],
        string $deploymentGroup = null
    ) {
        $this->logMessage = substr($logMessage, 0, self::MAX_CONTEXT_FIELD_LENGTH);
        $this->userName = $userName;
        $this->clientIp = $clientIp;
        $this->deploymentGroup = $deploymentGroup;
        $this->processLogContext($logContext);
    }

    /**
     * Process log context
     *  - If the log context is empty, set it to null so that it is not serialized as part of the log event.
     *  - Convert all non-string types to strings. This will prevent type mismatches in ELK if different log messages
     *    use the same key with different value types.
     *
     * @param array $logContext
     */
    private function processLogContext(array $logContext)
    {
        if (empty($logContext)) {
            $this->logContext = null;
            return;
        }

        $this->logContext = $this->stringifyArrayLeafValues($logContext);
    }

    /**
     * Sets the leaf values of nested arrays to be a string type, so that we don't have type collisions in ELK
     * @param array $data The array to change leaf nodes to strings on
     * @return array The modified array that includes only string types as leaf nodes
     */
    private function stringifyArrayLeafValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $data[$key] = $this->stringifyArrayLeafValues($value);
            } else {
                try {
                    $data[$key] = substr((string)$value, 0, self::MAX_CONTEXT_FIELD_LENGTH);
                } catch (Throwable $t) {
                    // Don't blow up the logging process if this can't be converted to a string,
                    // just change it to something valid so we can find the log message that failed and look at it later
                    $data[$key] = 'non-string type, unable to convert value to string';
                }
            }
        }

        return $data;
    }
}
