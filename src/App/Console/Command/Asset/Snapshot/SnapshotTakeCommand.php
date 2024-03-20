<?php

namespace Datto\App\Console\Command\Asset\Snapshot;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\SnapshotCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Take a snapshot of an asset.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class SnapshotTakeCommand extends AbstractCommand implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'asset:snapshot:take';

    private AssetService $assetService;
    private StorageInterface $storage;
    private SirisStorage $sirisStorage;
    private DateTimeService $dateTimeService;

    public function __construct(
        AssetService $assetService,
        StorageInterface $storage,
        SirisStorage $sirisStorage,
        DateTimeService $dateTimeService
    ) {
        parent::__construct();
        $this->assetService = $assetService;
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
        $this->dateTimeService = $dateTimeService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Take a snapshot of a provided asset')
            ->addArgument('assetKeyName', InputArgument::REQUIRED, 'The identifier of the asset you wish to take a snapshot of.')
            ->addArgument('tag', InputArgument::OPTIONAL, 'The tag to use as the snapshot name. If one is not provided, the current epoch time will be used.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('assetKeyName');
        $tag = $input->getArgument('tag');

        if ($tag === null) {
            $tag = strval($this->dateTimeService->getTime());
        }

        $this->logger->setAssetContext($assetKeyName);

        $asset = $this->assetService->get($assetKeyName);
        $isAgent = $asset->isType(AssetType::AGENT);
        $storageType = $isAgent ? StorageType::STORAGE_TYPE_FILE : StorageType::STORAGE_TYPE_BLOCK;
        $storageId = $this->sirisStorage->getStorageId($assetKeyName, $storageType);

        $context = new SnapshotCreationContext($tag);

        $this->logger->info('SUP0000 Take snapshot', ['tag' => $tag]);
        $snapshotId = $this->storage->takeSnapshot($storageId, $context);

        $output->writeln('Snapshot ID: ' . $snapshotId);
        return 0;
    }
}
