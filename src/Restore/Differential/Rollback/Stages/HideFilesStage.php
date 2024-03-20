<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Restore\FileExclusionService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Run file exclusions on the cloned volumes.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class HideFilesStage extends AbstractStage
{
    /** @var FileExclusionService */
    private $fileExclusionService;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param FileExclusionService $fileExclusionService
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        FileExclusionService $fileExclusionService
    ) {
        parent::__construct($context, $logger);

        $this->fileExclusionService = $fileExclusionService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        try {
            $this->fileExclusionService->exclude($this->context->getCloneSpec());
        } catch (Exception $e) {
            $this->logger->warning('DFR0009 Failed file exclusions but continuing on', ['exception' => $e]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        // nothing
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // rollback
    }
}
