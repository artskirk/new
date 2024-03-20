<?php

namespace Datto\Log;

use Psr\Log\NullLogger;

class DeviceNullLogger extends NullLogger implements DeviceLoggerInterface
{

    const CONTEXT_ID = "null-logger-context-id";

    public function removeFromGlobalContext(string $key)
    {
    }

    public function setAssetContext(string $key)
    {
    }

    public function setAgentlessSessionContext($sessionId)
    {
    }

    public function disableLogShipping()
    {
    }

    public function getContextId() : string
    {
        return self::CONTEXT_ID;
    }
}
