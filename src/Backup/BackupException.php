<?php

namespace Datto\Backup;

use Exception;
use Throwable;

/**
 * Exception thrown during the backup process.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupException extends Exception
{
    const STC_DATTO_IMAGE_NOT_FOUND = 4;
    const ERRNO_4_INTERRUPTED_FUNCTION = 6;
    const ERRNO_13_PERMISSION_DENIED = 7;
    const STC_FILE_NOT_FOUND = 8;
    const STC_NETWORK_COMMUNICATION = 10;
    const STC_INSUFFICIENT_RESOURCES = 11;
    const STC_FINAL_ERROR = 12;
    const STC_READ_ERROR = 13;
    const STC_WRITE_ERROR = 14;
    const AGENT_ERRORS = [256, 257, 258, 259, 260, 261, 262, 263, 272, 273, 274, 275, 276, 277, 278, 279, 288, 289,
        290, 336, 384, 385, 512, 513, 514, 515, 1024, 1024, 1026, 1027, 1028, 1029, 1280];

    /** @var array */
    private $context;

    /**
     * Construct the exception. Note: The message is NOT binary safe.
     * @link https://php.net/manual/en/exception.construct.php
     * @param string $message [optional] The Exception message to throw.
     * @param int $code [optional] The Exception code.
     * @param Throwable $previous [optional] The previous throwable used for the exception chaining.
     * @param array $context [optional] Any extra information in key:value form.
     */
    public function __construct($message = "", $code = 0, Throwable $previous = null, $context = [])
    {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Retrieve the context array for the exception.
     *
     * @return array
     */
    public function getContext() : array
    {
        return $this->context;
    }
}
