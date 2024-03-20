<?php

namespace Datto\App\Console\Command\Asset\RecoveryPoints;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Asset\RecoveryPoint\LocalSnapshotService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Destroy a recovery point that exists locally.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DestroyLocalRecoveryPointCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:local:destroy';

    /** @var LocalSnapshotService */
    private $localSnapshotService;

    public function __construct(
        LocalSnapshotService $localSnapshotService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->localSnapshotService = $localSnapshotService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Destroy a local recovery point.')
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

        $result = $this->localSnapshotService->destroy(
            $assetKey,
            [$snapshotEpoch],
            DestroySnapshotReason::MANUAL()
        );

        if (count($result->getDestroyedSnapshotEpochs()) === 1) {
            $output->writeln('destroyed local snapshot ' . $snapshotEpoch);
            return 0;
        } else {
            $output->writeln('failed to destroy local snapshot ' . $snapshotEpoch);
            return 1;
        }
    }
}
