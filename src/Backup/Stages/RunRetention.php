<?php

namespace Datto\Backup\Stages;

use Datto\Service\Retention\Exception\RetentionCannotRunException;
use Datto\Service\Retention\RetentionFactory;
use Datto\Service\Retention\RetentionService;
use Datto\Service\Retention\RetentionType;

/**
 * This backup stage runs backup retention.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RunRetention extends BackupStage
{
    /** @var RetentionFactory */
    private $retentionFactory;

    /** @var RetentionService */
    private $retentionService;

    public function __construct(RetentionFactory $retentionFactory, RetentionService $retentionService)
    {
        $this->retentionFactory = $retentionFactory;
        $this->retentionService = $retentionService;
    }

    public function commit()
    {
        $this->context->getLogger()->debug('BAK0006 Running retention.');

        $retention = $this->retentionFactory->create($this->context->getAsset(), RetentionType::LOCAL());

        try {
            $this->retentionService->doRetention($retention, false);
        } catch (RetentionCannotRunException $e) {
            // Don't fail backup if retention fails, it will get run again later by datto-retention-local.service
        }
    }

    public function cleanup()
    {
    }
}
