<?php

namespace Datto\App\Console\Command\Asset\SpeedSync;

use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\App\Console\Command\AbstractAssetCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to pause speedsync functionality for a given asset.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class PauseCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:speedsync:pause';

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncService;

    public function __construct(
        SpeedSyncMaintenanceService $speedSyncService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->speedSyncService = $speedSyncService;
    }


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Pause offsite sync for the given asset')
            ->addArgument('asset', InputArgument::REQUIRED, 'The asset for which to pause offsite sync');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('asset');

        $this->speedSyncService->pauseAsset($assetKeyName);
        return 0;
    }
}
