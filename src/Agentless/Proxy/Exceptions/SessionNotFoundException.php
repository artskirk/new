<?php

namespace Datto\Agentless\Proxy\Exceptions;

/**
 * Session not found exception.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class SessionNotFoundException extends \Exception
{
    /**
     * SessionNotFoundException constructor.
     * @param string $sessionId
     */
    public function __construct(string $sessionId)
    {
        parent::__construct("Agentless session $sessionId not found.");
    }
}
