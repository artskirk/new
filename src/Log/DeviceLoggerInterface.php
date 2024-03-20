<?php

namespace Datto\Log;

use Psr\Log\LoggerInterface;

interface DeviceLoggerInterface extends LoggerInterface
{
    public function removeFromGlobalContext(string $key);

    public function setAssetContext(string $key);

    public function setAgentlessSessionContext($sessionId);

    public function disableLogShipping();

    public function getContextId() : string;
}
