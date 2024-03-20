<?php

namespace Datto\App\Console\Command\Asset\Replication;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Replication\AssetMetadata;
use Datto\Replication\ReplicationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to demote a non-replicated asset to replicated asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class DemoteCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:replication:demote';

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
            FeatureService::FEATURE_REPLICATION_PROMOTE
        ];
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Demote a non-replicated asset into a replicated asset.')
            ->addArgument('assetKey', InputArgument::REQUIRED, 'Asset key')
            ->addArgument('primaryDeviceId', InputArgument::REQUIRED, 'Primary device ID')
            ->addArgument('publicKey', InputArgument::REQUIRED, 'Public key of the primary device')
            ->addArgument('assetMetadata', InputArgument::REQUIRED, 'Asset metadata');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asset = $this->assetService->get($input->getArgument('assetKey'));
        $primaryDeviceId = $input->getArgument('primaryDeviceId');
        $publicKey = $input->getArgument('publicKey');
        $assetMetadata = $this->constructAssetMetadata($asset->getKeyName(), $input->getArgument('assetMetadata'));

        $this->replicationService->demote($primaryDeviceId, $asset, $publicKey, $assetMetadata);
        return 0;
    }

    /**
     * @param string $assetKey
     * @param string $assetMetadataString
     * @return AssetMetadata
     */
    private function constructAssetMetadata(string $assetKey, string $assetMetadataString)
    {
        $assetMetadataArray = json_decode($assetMetadataString, true);
        return AssetMetadata::fromArray($assetKey, $assetMetadataArray);
    }
}
