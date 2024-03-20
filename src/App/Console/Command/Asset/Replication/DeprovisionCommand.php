<?php

namespace Datto\App\Console\Command\Asset\Replication;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Replication\ReplicationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deprovision a replicated asset
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DeprovisionCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:replication:deprovision';

    /** @var AssetService */
    private $assetService;

    /** @var ReplicationService */
    private $replicationService;

    public function __construct(
        AssetService $assetService,
        ReplicationService $replicationService
    ) {
        parent::__construct();

        $this->assetService = $assetService;
        $this->replicationService = $replicationService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_REPLICATION_TARGET
        ];
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Deprovision a replicated asset')
            ->addArgument('assetKey', InputArgument::REQUIRED, 'Asset key');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asset = $this->assetService->get($input->getArgument('assetKey'));

        $this->replicationService->deprovision($asset);
        return 0;
    }
}
