<?php

namespace Datto\App\Console\Command\Asset\Replication;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Replication\AssetMetadata;
use Datto\Replication\ReplicationService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provision a replicated asset
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ProvisionCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:replication:provision';

    /** @var ReplicationService */
    private $replicationService;

    public function __construct(
        ReplicationService $replicationService
    ) {
        parent::__construct();

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
            ->setDescription('Provision a replicated asset')
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
        $primaryDeviceId = $input->getArgument('primaryDeviceId');
        $publicKey = $input->getArgument('publicKey');
        $assetMetadata = $this->constructAssetMetadata(
            $input->getArgument('assetKey'),
            $input->getArgument('assetMetadata')
        );

        $this->replicationService->provision($primaryDeviceId, $publicKey, $assetMetadata);
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
