<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\AssetService;
use Datto\Replication\ReplicationService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;

/**
 * @author Jason Lodice <jlodice@datto.com>
 */
class AssetReplicationReceivedCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:replication:received';

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
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Notify OS2 that a new replication point has been received');
        $this->addArgument('universal', InputArgument::REQUIRED, 'Speedsync universal dataset identifier');
        $this->addArgument('source', InputArgument::REQUIRED, 'Source snapshot epoch');
        $this->addArgument('dest', InputArgument::REQUIRED, 'Destination snapshot epoch');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $universal = $input->getArgument('universal');
        $sourceSnap = (int)$input->getArgument('source');
        $destSnap = (int)$input->getArgument('dest');

        $assetKey = $this->parseAssetKey($universal);
        $asset = $this->assetService->get($assetKey);

        $this->logger->debug("REP0001 Speedsync post-receive-zfs hook called with $assetKey@$destSnap");

        if ($asset->getOriginDevice()->isReplicated()) {
            $this->replicationService->reconcileReplicatedAssetInfo($asset, $destSnap);
        }
        return 0;
    }

    /**
     * Extract dataset name from universal key.
     *
     * @param string $universal
     *
     * @return string
     */
    private function parseAssetKey(string $universal): string
    {
        // This will work well for assets that use new uuid-based asset keys as the ZFS dataset name
        // will match. For assets using old style key there's no such guarantee and as such we do not
        // try to handle them here as all replicated assets will use new style anyway.
        if (!preg_match('/(\d+)\+(?<dataset_name>[a-z0-9]+)\+(agent|nas)/', $universal, $matches)) {
            throw new RuntimeException('Invalid speedsync universal zfs indentifier string');
        }

        return $matches['dataset_name'];
    }
}
