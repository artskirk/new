<?php

namespace Datto\Restore\Differential\Rollback\Stages;

use Datto\Resource\DateTimeService;
use Datto\Restore\Differential\Rollback\DifferentialRollbackContext;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Log\DeviceLoggerInterface;

/**
 * Save the restore.
 *
 * Giovanni Carvelli <gcarvelli@datto.com>
 */
class SaveRestoreStage extends AbstractStage
{
    /** @var RestoreService */
    private $restoreService;

    /** @var DateTimeService */
    private $dateTimeService;

    /**
     * @param DifferentialRollbackContext $context
     * @param DeviceLoggerInterface $logger
     * @param RestoreService $restoreService
     * @param DateTimeService $dateTimeService
     */
    public function __construct(
        DifferentialRollbackContext $context,
        DeviceLoggerInterface $logger,
        RestoreService $restoreService,
        DateTimeService $dateTimeService
    ) {
        parent::__construct($context, $logger);

        $this->restoreService = $restoreService;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $snapshot = $this->context->getSnapshot();
        $targetName = $this->context->getTargetName();
        $fullSuffix = $this->context->getCloneSpec()->getSuffix();
        $restoreType = $this->getRestoreType();

        $this->logger->debug('DFR0003 Adding restore entry ...');

        $options = [
            DifferentialRollbackService::TARGET_NAME_RESTORE_OPTION_KEY => $targetName,
            DifferentialRollbackService::FULL_SUFFIX_OPTION_KEY => $fullSuffix,
        ];

        $restore = $this->restoreService->create(
            $assetKey,
            $snapshot,
            $restoreType,
            $this->dateTimeService->getTime(),
            $options
        );

        $this->restoreService->getAll();
        $this->restoreService->add($restore);
        $this->restoreService->save();

        $this->context->setRestore($restore);
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $assetKey = $this->context->getAsset()->getKeyName();
        $snapshot = $this->context->getSnapshot();
        $restoreType = $this->getRestoreType();

        $this->logger->info('DFR0012 Removing restore entry ...');

        $restore = $this->restoreService->find($assetKey, $snapshot, $restoreType);

        $this->restoreService->delete($restore);
        $this->restoreService->save();
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // nothing
    }

    private function getRestoreType(): string
    {
        $suffix = $this->context->getCloneSpec()->getSuffix();
        return $suffix === RestoreType::DIFFERENTIAL_ROLLBACK
            ? RestoreType::DIFFERENTIAL_ROLLBACK
            : RestoreType::BMR;
    }
}
