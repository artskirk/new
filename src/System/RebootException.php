<?php

namespace Datto\System;

use Exception;

/**
 * Class RebootException
 *
 * Implements an exception for reboot.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RebootException extends Exception
{
    /**
     * RebootException constructor.
     *
     * @param string $message
     */
    public function __construct($message)
    {
        parent::__construct($message);
    }
}
