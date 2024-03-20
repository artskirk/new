<?php

namespace Datto\Restore\File\Stages;

use Datto\Restore\File\AbstractFileRestoreStage;
use Datto\Restore\File\FileRestoreContext;
use Datto\Restore\FileExclusionService;
use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Reads the file exclusions file and removes its entries from the restore.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class HideFilesStage extends AbstractFileRestoreStage
{
    /** @var FileExclusionService */
    private $fileExclusionService;

    /**
     * @param FileRestoreContext $context
     * @param DeviceLoggerInterface $logger
     * @param FileExclusionService $fileExclusionService
     */
    public function __construct(
        FileRestoreContext $context,
        DeviceLoggerInterface $logger,
        FileExclusionService $fileExclusionService
    ) {
        parent::__construct($context, $logger);
        $this->fileExclusionService = $fileExclusionService;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        try {
            $this->fileExclusionService->exclude($this->context->getCloneSpec());
        } catch (Exception $e) {
            // It's possible for volumes to end up being mounted
            // read-only due to ntfs corruption. In this case, skip excluding
            // and carry on, since this is not a critical failure and the
            // user might still be able to recover files.
            //
            // (MountPointHelper already logs an error message for this)
            //
            // Just quit what we're doing and pretend everything's fine.
            // If the mount truly failed completely, we will fail futher
            // down in the process.
            $this->logger->warning('FEX0004 Skipping file exclusions because read-write mount failed.');
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // nothing
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        // nothing
    }
}
