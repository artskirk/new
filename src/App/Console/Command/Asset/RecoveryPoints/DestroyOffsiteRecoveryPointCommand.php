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
 * Destroy a recovery point that exists offsite.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DestroyOffsiteRecoveryPointCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:offsite:destroy';

    /** @var OffsiteSnapshotService */
    private $offsiteSnapshotService;

    public function __construct(
        OffsiteSnapshotService $offsiteSnapshotService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->offsiteSnapshotService = $offsiteSnapshotService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Destroy an offsite recovery point.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to target.')
            ->addArgument('snapshot', InputArgument::REQUIRED, 'Snapshot to destroy.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $snapshotEpoch = $input->getArgument('snapshot');

        $result = $this->offsiteSnapshotService->destroy(
            $assetKey,
            [$snapshotEpoch],
            DestroySnapshotReason::MANUAL()
        );

        if (count($result->getDestroyedSnapshotEpochs()) === 1) {
            $output->writeln('destroyed offsite snapshot ' . $snapshotEpoch);
            return 0;
        } else {
            $output->writeln('failed to destroy offsite snapshot ' . $snapshotEpoch);
            return 1;
        }
    }
}
