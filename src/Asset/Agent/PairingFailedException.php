<?php

namespace Datto\Asset\Agent;

use Exception;
use Throwable;

/**
 * Dedicated exception for the agent pairing process.
 *
 * This is used to indicate that the pairing request was denied because the agent didn't get a valid
 * pairing ticket from device-web.
 * @author Peter Geer <pgeer@datto.com>
 */
class PairingFailedException extends Exception
{
    const MESSAGE = 'Add Agent failed: pairing validation could not be completed';

    public function __construct($message = self::MESSAGE, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
