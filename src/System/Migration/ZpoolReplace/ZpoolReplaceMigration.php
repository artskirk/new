<?php

namespace Datto\System\Migration\ZpoolReplace;

use Datto\System\Migration\AbstractMigration;
use Datto\System\Migration\Context;
use Datto\System\Migration\ZpoolReplace\Stage\SetAutoExpandOnStage;
use Datto\System\Migration\ZpoolReplace\Stage\ZpoolReplaceStage;
use Datto\System\Transaction\Transaction;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Creates stages for a zpool replace migration
 *
 * @author Charles Shapleigh <cshapleigh@datto,com>
 */
class ZpoolReplaceMigration extends AbstractMigration
{
    const TYPE = "ZpoolReplaceMigration";

    /** @var ZpoolMigrationValidationService */
    private $validationService;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param Transaction $transaction
     * @param ZpoolMigrationValidationService $validationService
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Transaction $transaction,
        ZpoolMigrationValidationService $validationService
    ) {
        parent::__construct($logger, $filesystem, $transaction);
        $this->validationService = $validationService ?: new ZpoolMigrationValidationService();
    }

    /**
     * @inheritdoc
     */
    public function rebootIfNeeded()
    {
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return static::TYPE;
    }

    /**
     * @inheritdoc
     */
    public function validate(array $sources, array $targets)
    {
        $this->validationService->validate($sources, $targets);
    }

    /**
     * @inheritdoc
     */
    protected function createStages(Context $context): array
    {
        $stages = [];
        $stages[] = new SetAutoExpandOnStage($context);
        $stages[] = new ZpoolReplaceStage($context);

        return $stages;
    }
}
