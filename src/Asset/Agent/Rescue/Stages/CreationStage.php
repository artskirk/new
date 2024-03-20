<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Log\DeviceLoggerInterface;
use Datto\System\Transaction\Stage;

/**
 * Abstract class that all stages of rescue agent creation must implement.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
abstract class CreationStage implements Stage
{
    protected DeviceLoggerInterface $logger;
    protected RescueAgentCreationContext $context;

    public function __construct(
        DeviceLoggerInterface $logger,
        RescueAgentCreationContext $context
    ) {
        $this->logger = $logger;
        $this->context = $context;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context): void
    {
        // not yet implemented
    }

    /**
     * Get the initial status message for this stage of rescue agent creation.
     *
     * @return string
     */
    abstract public function getStatusMessage(): string;

    public function cleanup(): void
    {
        // some stages will set log context to rescue agent UUID, make sure it's set back
        $this->logger->setAssetContext($this->context->getSourceAgent()->getKeyName());
    }

    public function rollback(): void
    {
        // no rollback by default
    }
}
