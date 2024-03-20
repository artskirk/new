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
 * Purge all local recovery points for an asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class PurgeLocalRecoveryPointCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:local:purge';

    /** @var LocalSnapshotService */
    private $destroyLocalSnapshotService;

    public function __construct(
        LocalSnapshotService $localSnapshotService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->destroyLocalSnapshotService = $localSnapshotService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Purge all local recovery points.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to target.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');

        $this->destroyLocalSnapshotService->purge(
            $assetKey,
            DestroySnapshotReason::MANUAL()
        );

        $output->writeln('local snapshots purged');
        return 0;
    }
}
