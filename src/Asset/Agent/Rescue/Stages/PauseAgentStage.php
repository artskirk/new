<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Log\DeviceLoggerInterface;

/**
 * Stage to pause the source agent after creating a rescue agent,
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class PauseAgentStage extends CreationStage
{
    const STATUS_MESSAGE = 'pauseAgent';

    private AgentService $agentService;
    private bool $agentHasBeenPaused = false;

    public function __construct(
        RescueAgentCreationContext $context,
        AgentService $agentService,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($logger, $context);

        $this->agentService = $agentService;
    }

    /**
     * Pause the agent if it is not paused.
     */
    public function commit(): void
    {
        $sourceAgent = $this->context->getSourceAgent();
        if (!$sourceAgent->getLocal()->isPaused()) {
            $sourceAgent->getLocal()->setPaused(true);
            $this->agentService->save($sourceAgent);
            $this->agentHasBeenPaused = true;
        }
    }

    /**
     * Restart the agent if things fail.
     */
    public function rollback(): void
    {
        if ($this->agentHasBeenPaused) {
            $sourceAgent = $this->context->getSourceAgent();
            $sourceAgent->getLocal()->setPaused(false);
            $this->agentService->save($sourceAgent);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }
}
