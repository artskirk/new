<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Cloud\SpeedSync;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Run the necessary commands to set up SpeedSync for use with the rescue agent
 *
 * @author Christopher Bitler <cbitler@datto.com>
 */
class SpeedSyncSetup extends CreationStage
{
    const STATUS_MESSAGE = 'speedSyncSetup';

    private SpeedSync $speedSync;

    public function __construct(
        RescueAgentCreationContext $context,
        SpeedSync $speedSync,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($logger, $context);

        $this->speedSync = $speedSync;
    }

    /**
     * Attempts to set up SpeedSync for the rescue agent
     *
     * Note: Since the dataset is added to SpeedSync when a point is offsited if it is not already there,
     * if this fails, it will not cause the rescue agent creation process to rollback, it will just log a warning.
     */
    public function commit(): void
    {
        $this->logger->setAssetContext($this->context->getRescueAgentUuid());

        $zfsPath = $this->context->getRescueAgent()->getDataset()->getZfsPath();
        try {
            $status = $this->speedSync->add($zfsPath, SpeedSync::TARGET_CLOUD);
            if ($status !== 0) {
                $this->logger->error('RSC3001 Could not add rescue agent dataset to SpeedSync.', ['exitCode' => $status]);
            }
        } catch (Exception $ex) {
            $this->logger->error('RSC3002 Exception encountered attempting to add rescue agent dataset to SpeedSync', ['exception' => $ex]);
        }
    }

    /**
     * Attempt to remove the dataset from SpeedSync if an issue has occurred while creating the rescue agent
     */
    public function rollback(): void
    {
        $zfsPath = $this->context->getRescueAgent()->getDataset()->getZfsPath();

        try {
            $status = $this->speedSync->remove($zfsPath);
            if ($status !== 0) {
                $this->logger->error('RSC3003 Could not remove rescue agent dataset from SpeedSync during rollback.', ['exitCode' => $status]);
            }
        } catch (Exception $ex) {
            $this->logger->error('RSC3004 Exception encountered attempting to remove rescue agent dataset to SpeedSync', ['exception' => $ex]);
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
