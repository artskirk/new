<?php

namespace Datto\Log;

use Datto\Agentless\Proxy\Log\AgentlessLogHandler;
use Datto\Config\DeviceConfig;
use Datto\Log\Handler\AssetAlertHandler;
use Datto\Log\Handler\AssetHandler;
use Datto\Log\Handler\AssetRemoteHandler;
use Datto\Log\Handler\DeviceConsoleHandler;
use Datto\Log\Handler\DeviceHandler;
use Datto\Log\Handler\EventHandler;
use Datto\Log\Processor\AlertCodeProcessor;
use Datto\Log\Processor\ApiLogProcessor;
use Datto\Log\Processor\CefProcessor;
use Datto\Log\Processor\ChannelProcessor;
use Datto\Log\Processor\ContextIdProcessor;
use Datto\Log\Processor\ContextProcessor;
use Datto\Log\Processor\UserProcessor;
use Monolog\Logger;

/**
 * Injectable version of device logger
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class DeviceLogger extends Logger implements DeviceLoggerInterface
{
    const DEVICE_LOGGER_NAME = 'device-logger';
    const CONTEXT_ASSET = 'asset';
    const CONTEXT_SESSION_ID = 'session_id';
    const CONTEXT_NO_SHIP = 'no_ship';

    /** @var ContextProcessor */
    private $contextProcessor;

    /** @var ContextIdProcessor */
    private $contextIdProcessor;

    /** @var EventHandler */
    private $eventHandler;

    public function __construct(
        DeviceConfig $deviceConfig,
        ContextProcessor $contextProcessor,
        ContextIdProcessor $contextIdProcessor,
        AlertCodeProcessor $alertCodeProcessor,
        ChannelProcessor $channelProcessor,
        UserProcessor $userProcessor,
        CefProcessor $cefProcessor,
        ApiLogProcessor $apiLogProcessor,
        DeviceHandler $deviceHandler,
        DeviceConsoleHandler $deviceConsoleHandler,
        AssetHandler $assetHandler,
        AssetRemoteHandler $assetRemoteHandler,
        AssetAlertHandler $assetAlertHandler,
        AgentlessLogHandler $agentlessLogHandler,
        EventHandler $eventHandler
    ) {
        $this->contextProcessor = $contextProcessor;
        $this->contextIdProcessor = $contextIdProcessor;
        $this->eventHandler = $eventHandler;

        $processors = [
            $contextIdProcessor,
            $contextProcessor,
            $alertCodeProcessor,
            $channelProcessor,
            $userProcessor,
            $cefProcessor,
            $apiLogProcessor
        ];

        $handlers = [
            $deviceHandler,
            $deviceConsoleHandler,
            $assetHandler,
            $assetRemoteHandler,
            $agentlessLogHandler
        ];

        // FeatureService cannot be used here at this time due to its dependency on this class
        if (!$deviceConfig->has('disableLogShipping')) {
            $handlers[] = $this->eventHandler;
        }

        // Must be last because it logs when sending alert emails
        // TODO: change alerting so it happens outside the log handler
        $handlers[] = $assetAlertHandler;

        parent::__construct(self::DEVICE_LOGGER_NAME, $handlers, $processors);
    }

    public function removeFromGlobalContext(string $key)
    {
        $this->contextProcessor->removeFromGlobalContext($key);
    }

    /**
     * Set the context for the logger.
     *
     * @param string $key
     */
    public function setAssetContext(string $key)
    {
        $this->contextProcessor->updateGlobalContext([self::CONTEXT_ASSET => $key]);
    }

    public function setAgentlessSessionContext($sessionId)
    {
        $this->contextProcessor->updateGlobalContext([DeviceLogger::CONTEXT_SESSION_ID => $sessionId]);
    }

    public function disableLogShipping()
    {
        $this->eventHandler->disable();
    }

    public function getContextId() : string
    {
        return $this->contextIdProcessor->getContextId();
    }
}
