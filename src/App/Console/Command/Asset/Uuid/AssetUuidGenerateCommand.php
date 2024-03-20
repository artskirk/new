<?php

namespace Datto\App\Console\Command\Asset\Uuid;

use Datto\Asset\OrphanDatasetService;
use Datto\Asset\AssetUuidService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

/**
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class AssetUuidGenerateCommand extends Command
{
    protected static $defaultName = 'asset:uuid:generate';

    /** @var AssetUuidService */
    protected $assetUuidService;

    /** @var OrphanDatasetService */
    protected $orphanDatasetService;

    public function __construct(
        AssetUuidService $assetUuidService,
        OrphanDatasetService $orphanDatasetService
    ) {
        parent::__construct();

        $this->assetUuidService = $assetUuidService;
        $this->orphanDatasetService = $orphanDatasetService;
    }

    protected function configure()
    {
        $this
            ->setDescription("Generate a UUID for all assets that don't have one.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->assetUuidService->generateMissing();
        $this->orphanDatasetService->createAllMissingUuids();
        return 0;
    }
}
