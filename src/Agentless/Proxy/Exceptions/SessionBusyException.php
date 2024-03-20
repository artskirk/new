<?php

namespace Datto\Agentless\Proxy\Exceptions;

/**
 * DESCRIPTION
 * @author Mario Rial <mrial@datto.com>
 */
class SessionBusyException extends \Exception
{
    /**
     * @param string $sessionId
     */
    public function __construct(string $sessionId)
    {
        parent::__construct("Agentless session $sessionId is busy.");
    }
}
