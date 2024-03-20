<?php

namespace Datto\Restore\Export\Stages;

use Datto\Restore\FileExclusionService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Run file exclusions on the cloned volumes.
 *
 * @author Rob Hamilton <rhamilton@datto.com>
 */
class HideFilesStage extends AbstractStage
{
    /** @var FileExclusionService */
    private $fileExclusionService;

    /**
     * @param DeviceLoggerInterface $logger
     * @param FileExclusionService $fileExclusionService
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        FileExclusionService $fileExclusionService
    ) {
        $this->fileExclusionService = $fileExclusionService;
        $this->setLogger($logger);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $alwaysExcludeHasDataFiles = !$this->context->getEnableAgentInRestoredVm();

        try {
            $this->logger->debug('HFS0002 Removing files from restore');
            $this->fileExclusionService->exclude($this->context->getCloneSpec(), $alwaysExcludeHasDataFiles);
        } catch (Exception $e) {
            $this->logger->warning('HFS0003 Failed file exclusions but continuing on', ['exception' => $e]);
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
