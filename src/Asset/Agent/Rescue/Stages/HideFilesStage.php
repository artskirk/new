<?php

namespace Datto\Asset\Agent\Rescue\Stages;

use Datto\Asset\Agent\Rescue\RescueAgentCreationContext;
use Datto\Restore\FileExclusionService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Reads the file exclusions file and removes its entries from the restore.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class HideFilesStage extends CreationStage
{
    const STATUS_MESSAGE = 'prepareDataset';

    private FileExclusionService $fileExclusionService;

    /**
     * @param RescueAgentCreationContext $context
     * @param FileExclusionService $fileExclusionService
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        RescueAgentCreationContext $context,
        FileExclusionService $fileExclusionService,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($logger, $context);

        $this->fileExclusionService = $fileExclusionService;
    }

    /**
     * Get the initial status message for this stage of rescue agent creation.
     *
     * @return string
     */
    public function getStatusMessage(): string
    {
        return self::STATUS_MESSAGE;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit(): void
    {
        $this->logger->setAssetContext($this->context->getRescueAgentUuid());

        // TODO revisit whether file exclusion failure should fail the whole vm creation
        try {
            $this->fileExclusionService->exclude($this->context->getCloneSpec());
        } catch (Exception $e) {
            $this->logger->warning('RSC0023 Failed file exclusions but continuing on', ['exception' => $e]);
        }
    }
}
