<?php

namespace Datto\Util;

use Exception;
use Throwable;

/**
 * Thrown by the RetryHandler when all retry attempts are exhausted.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RetryAttemptsExhaustedException extends Exception implements \JsonSerializable
{
    const DEFAULT_MAX_ATTEMPT_MESSAGES = 3;
    const FUNCTION_EXECUTION_ATTEMPTS_EXHAUSTED = 'Function execution attempts exhausted';

    /** @var Throwable[] */
    private $exceptions;

    /**
     * @param Throwable[] $exceptions
     * @param int $maxAttemptMessages
     */
    public function __construct(
        array $exceptions,
        int $maxAttemptMessages = self::DEFAULT_MAX_ATTEMPT_MESSAGES
    ) {
        $this->exceptions = $exceptions;

        $message = self::FUNCTION_EXECUTION_ATTEMPTS_EXHAUSTED;
        if (!empty($exceptions)) {
            $message .= ': ' . $this->exceptionsToMessage($exceptions, $maxAttemptMessages);

            $last = end($exceptions);
            $code = $last->getCode();
            $previous = $last;
        }

        parent::__construct($message, $code ?? 0, $previous ?? null);
    }

    /**
     * @return Throwable[]
     */
    public function getAttemptExceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return array_map(fn($e) => $e->getMessage(), $this->exceptions);
    }

    /**
     * @param Throwable[] $exceptions
     * @param int $maxAttemptMessages
     * @return string
     */
    private function exceptionsToMessage(array $exceptions, int $maxAttemptMessages): string
    {
        $messages = [];
        foreach ($exceptions as $attempt => $exception) {
            $messages[] = "$attempt => '{$exception->getMessage()}'";
        }

        $messages = array_slice($messages, 0 - $maxAttemptMessages, null, true);
        return implode(', ', $messages);
    }
}
