<?php

namespace Datto\Verification\Stages;

use Datto\Restore\FileExclusionService;
use Datto\Verification\VerificationResultType;
use Exception;

/**
 * Reads the file exclusions file and removes its entries from the verification clone.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class HideFilesStage extends VerificationStage
{
    /** @var FileExclusionService */
    private $fileExclusionService;

    public function __construct(FileExclusionService $fileExclusionService)
    {
        $this->fileExclusionService = $fileExclusionService;
    }

    public function commit()
    {
        $cloneSpec = $this->context->getCloneSpec();

        try {
            $this->fileExclusionService->exclude($cloneSpec);
        } catch (Exception $e) {
            $this->logger->warning('VER0700 Failed file exclusions but continuing on', ['exception' => $e]);
        }

        if (!$this->result) {
            $this->setResult(VerificationResultType::SUCCESS());
        }
    }

    public function cleanup()
    {
        // nothing to see here
    }
}
