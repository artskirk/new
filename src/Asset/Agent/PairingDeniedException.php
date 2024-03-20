<?php

namespace Datto\Asset\Agent;

use Throwable;

/**
 * Dedicated exception for secure pairing failure.
 * This is used to indicate that the pairing request was denied by device-web.
 * @author Matthew Cheman <mcheman@datto.com>
 */
class PairingDeniedException extends PairingFailedException
{
    const MESSAGE = 'Add Agent failed: pairing not allowed to this device';

    public function __construct($message = self::MESSAGE, $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
