<?php

namespace Datto\App\Console\Command\Restore\Differential;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Restore\RestoreType;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes a differential rollback target.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class RemoveCommand extends AbstractCommand
{
    protected static $defaultName = 'restore:differential:remove';

    /** @var DifferentialRollbackService */
    private $differentialRollbackService;

    /**
     * @param DifferentialRollbackService $incrementalRestoreService
     */
    public function __construct(
        DifferentialRollbackService $incrementalRestoreService
    ) {
        parent::__construct();

        $this->differentialRollbackService = $incrementalRestoreService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_DIFFERENTIAL_ROLLBACK];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a differential restore target')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to restore')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot to restore');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $snapshot = $input->getArgument('snapshot');
        $suffix = RestoreType::DIFFERENTIAL_ROLLBACK;

        $this->differentialRollbackService->remove($assetKey, $snapshot, $suffix);
        return 0;
    }
}
