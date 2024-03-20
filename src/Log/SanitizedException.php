<?php

namespace Datto\Log;

use Datto\Utility\Security\SecretString;
use Datto\Utility\Security\SecretScrubber;
use Exception;
use Throwable;

/**
 * This exception class ensures that sensitive strings do not get output from the __toString() method.
 * It should be used to wrap other exceptions so that passwords do not end up in logs.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class SanitizedException extends Exception
{
    private array $secretStrings;

    private SecretScrubber $secretScrubber;

    /**
     * @param Throwable|null $previous the wrapped exception
     * @param string[]|SecretString[] $secretStrings any strings that should be omitted from the __toString() output
     * @param bool $scrubMessage True to scrub the message, false to skip scrubbing the message. This is a stopgap
     *   measure for cases where exception messages are shown directly in the UI. We don't want user input to mangle
     *   messages they see. This should only be set to false when the exception message doesn't contain secrets.
     * @param string|null $message
     * @todo remove the $scrubMessage parameter once exceptions are no longer displayed directly in the UI
     */
    public function __construct(
        Throwable $previous = null,
        array $secretStrings = null,
        bool $scrubMessage = true,
        string $message = null
    ) {
        $this->secretScrubber = new SecretScrubber();
        $this->secretStrings = [];
        if ($secretStrings !== null) {
            foreach ($secretStrings as $secret) {
                if ($secret instanceof SecretString) {
                    $this->secretStrings[] = $secret->getSecret();
                } else {
                    $this->secretStrings[] = $secret;
                }
            }
        }

        $code = $previous ? $previous->getCode() : 0;
        if ($previous !== null && $message === null) {
            if ($scrubMessage) {
                $message = $this->secretScrubber->scrubSecrets($this->secretStrings, $previous->getMessage());
            } else {
                $message = $previous->getMessage();
            }
        }
        parent::__construct($message, $code, $previous);
    }

    /**
     * @inheritdoc
     */
    public function __toString()
    {
        // stack trace truncates param values after 15 characters
        return $this->secretScrubber->scrubSecrets(
            $this->secretStrings,
            parent::__toString(),
            15,
            '...'
        );
    }
}
