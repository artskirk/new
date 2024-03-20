<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Replication\ReplicationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command will call a new DWI endpoint, download all replicated assets, and perform updates on local device.
 *
 * @author Jack Corrigan <jcorrigan@datto.com>
 */
class AssetReplicationReconcileCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:replication:reconcile';

    /** @var ReplicationService */
    private $replicationService;

    public function __construct(
        ReplicationService $replicationService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->replicationService = $replicationService;
    }

    /**
     * Configure the console command.
     */
    protected function configure()
    {
        $this->setDescription('get config data for replicated assets and update key files with changes');
    }

    /**
     * Call reconcileReplicatedAssetInfo() from PeerReplicationService
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->replicationService->reconcileAssets();
        return 0;
    }
}
