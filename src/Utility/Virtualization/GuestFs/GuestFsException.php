<?php

namespace Datto\Utility\Virtualization\GuestFs;

use Exception;

/**
 * Represents an exception that is thrown from the GuestFs library abstraction
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class GuestFsException extends Exception
{
    /** @var string Error string from the underlying library */
    private string $details;

    /**
     * @param string $message
     * @param string $details
     */
    public function __construct(string $message, string $details = '')
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /**
     * @return string Details from the underlying library
     */
    public function getDetails(): string
    {
        return $this->details;
    }
}
