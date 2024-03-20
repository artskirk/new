<?php

namespace Datto\App\Console\Command\Asset\RecoveryPoints;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\OffsiteSnapshotService;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Purge all offsite recovery points for an asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class PurgeOffsiteRecoveryPointsCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:offsite:purge';

    /** @var OffsiteSnapshotService */
    private $destroyOffsiteSnapshotService;

    public function __construct(
        OffsiteSnapshotService $localSnapshotService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->destroyOffsiteSnapshotService = $localSnapshotService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Purge all offsite recovery points.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to target.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');

        $this->destroyOffsiteSnapshotService->purge(
            $assetKey,
            DestroySnapshotReason::MANUAL(),
            null
        );

        $output->writeln('offsite snapshots purged');
        return 0;
    }
}
