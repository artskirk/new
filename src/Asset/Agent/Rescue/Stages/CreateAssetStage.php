<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\AgentRepository;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Stage to create a rescue agent asset by cloning the key files on disk.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CreateAssetStage extends CreationStage
{
    const STATUS_MESSAGE = 'createAsset';

    private AgentService $agentService;
    private AgentRepository $agentRepository;
    private Filesystem $filesystem;

    public function __construct(
        RescueAgentCreationContext $context,
        AgentService $agentService,
        AgentRepository $agentRepository,
        Filesystem $filesystem,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($logger, $context);

        $this->agentService = $agentService;
        $this->agentRepository = $agentRepository;
        $this->filesystem = $filesystem;
    }

    /**
     * Creates a new rescue agent and saves it to disk.
     */
    public function commit(): void
    {
        $sourceAgent = $this->context->getSourceAgent();
        $rescueAgent = $sourceAgent->createRescueAgent(
            $this->context->getRescueAgentName(),
            $this->context->getRescueAgentUuid(),
            $this->context->getSnapshotEpoch(),
            $this->filesystem,
            $this->agentService
        );
        $this->context->setRescueAgent($rescueAgent);
    }

    /**
     * Delete the agent key files if the commit was successful.
     */
    public function rollback(): void
    {
        $rescueAgent = $this->context->getRescueAgent();

        if ($rescueAgent !== null) {
            $this->agentRepository->destroy($rescueAgent->getKeyName());
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
