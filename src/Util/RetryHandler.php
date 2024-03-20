<?php

namespace Datto\Util;

use Datto\Log\LoggerFactory;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Functions that implement generic retrial logic.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class RetryHandler
{
    const DEFAULT_RETRY_ATTEMPTS = 5;
    const DEFAULT_SLEEP_SECONDS = 5;
    const DEFAULT_QUIET = false;

    private DeviceLoggerInterface $logger;
    private Sleep $sleep;

    public function __construct(DeviceLoggerInterface $logger = null, Sleep $sleep = null)
    {
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Reattempts the execution of the passed function $numberOfAttempts until it succeeds.
     * NOTE: The passed function should throw any Throwable to indicate an error, or return any value on success.
     *
     * @param callable $function
     * @param int $numberOfAttempts
     * @param int $secondsAfterAttempt
     * @param bool $quiet True to be quiet, false to log attempts and failures.
     * @param int $maxAttemptMessages Number of attempts to include in exception message.
     * @return mixed
     */
    public function executeAllowRetry(
        callable $function,
        int $numberOfAttempts = self::DEFAULT_RETRY_ATTEMPTS,
        int $secondsAfterAttempt = self::DEFAULT_SLEEP_SECONDS,
        bool $quiet = self::DEFAULT_QUIET,
        int $maxAttemptMessages = RetryAttemptsExhaustedException::DEFAULT_MAX_ATTEMPT_MESSAGES
    ) {
        $exceptions = [];

        for ($attempt = 0; $attempt < $numberOfAttempts; $attempt++) {
            try {
                return $function();
            } catch (Throwable $throwable) {
                $exceptions[$attempt] = $throwable;

                if (!$quiet) {
                    $this->logger->warning('RTU0003 Failure executing function', ['error' => $throwable->getMessage()]);
                }

                if ($attempt < ($numberOfAttempts - 1)) {
                    if (!$quiet) {
                        $this->logger->warning('RTU0005 Reattempting function after waiting', ['secondsAfterAttempt' => $secondsAfterAttempt]);
                    }
                    $this->sleep->sleep($secondsAfterAttempt);
                }
            }
        }

        $exception = new RetryAttemptsExhaustedException($exceptions, $maxAttemptMessages);

        if (!$quiet) {
            $this->logger->error('RTU0004 Retry attempts exhausted.', ['error' => $exception->getMessage()]);
        }

        throw $exception;
    }
}
