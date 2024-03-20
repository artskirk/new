<?php

namespace Datto\Cloud;

use Exception;

/**
 * An Exception to be thrown when speedsync is used for a path that has not been added to speedsync.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class SpeedSyncPathException extends Exception
{
    /**
     * @param string $message
     * @param int $code
     * @param Exception|null $previous
     */
    public function __construct($message = "", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
