<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Log\DeviceLoggerInterface;

/**
 * Rescue agent backup stage to perform an initial snapshot
 *
 * @author Andrew Cope <acope@datto.com>
 */
class BackupRescueAgentStage extends CreationStage
{
    const STATUS_MESSAGE = 'backupRescueAgent';

    /** @var AgentService */
    private $agentService;

    /** @var RescueAgentService */
    private $rescueAgentService;

    public function __construct(
        RescueAgentCreationContext $context,
        AgentService $agentService,
        DeviceLoggerInterface $logger,
        RescueAgentService $rescueAgentService
    ) {
        parent::__construct($logger, $context);

        $this->agentService = $agentService;
        $this->rescueAgentService = $rescueAgentService;
    }

    /**
     * Take a snapshot of the rescue agent and add a recovery point if successful
     */
    public function commit(): void
    {
        $this->logger->setAssetContext($this->context->getRescueAgentUuid());

        // Use the point the rescue agent was created from as the first backup.
        // Add one so we don't have the same snapshot as the origin (it can interfere with zfs promote)
        $snapshot = $this->context->getSnapshotEpoch() + 1;

        $this->logger->info('RSC2001 Backup requested');

        $rescueAgent = $this->context->getRescueAgent();

        $this->rescueAgentService->doBackup($rescueAgent, $snapshot);

        $this->logger->info('RSC2002 Backup complete');

        $rescueAgent->getLocal()->getRecoveryPoints()->add(new RecoveryPoint($snapshot));
        $this->agentService->save($rescueAgent);

        // TODO: update agent info if anything specific needs updating
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }
}
