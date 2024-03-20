<?php

namespace Datto\App\Console\Command\Asset\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Backup\BackupManagerFactory;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Command that exposes the ability to stop a specific or all backups
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class BackupStopCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:backup:stop';

    /** @var AssetService */
    private $assetService;

    /** @var BackupManagerFactory */
    private $backupManagerFactory;

    public function __construct(
        AssetService $assetService,
        BackupManagerFactory $backupManagerFactory
    ) {
        parent::__construct();

        $this->assetService = $assetService;
        $this->backupManagerFactory = $backupManagerFactory;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_ASSET_BACKUPS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Stops specific or all backups')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Cancel all backups')
            ->addArgument('hostname', InputArgument::OPTIONAL, 'The hostname|IP with a backup you wish to stop');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetName = $input->getArgument('hostname');
        $allFlag = $input->getOption('all');

        if (!$allFlag && empty($assetName)) {
            throw new \Exception('Asset key name must be supplied if the --all option is not present');
        }

        if ($allFlag) {
            $this->cancelAllBackups($output);
        } else {
            $asset = $this->assetService->get($assetName);
            $this->cancelBackup($asset);
        }
        return 0;
    }

    /**
     * Cancel all backups
     *
     * @param OutputInterface $output
     */
    private function cancelAllBackups(OutputInterface $output): void
    {
        $assets = $this->assetService->getAll();
        foreach ($assets as $asset) {
            try {
                $this->cancelBackup($asset);
            } catch (Throwable $throwable) {
                // Display to the user and continue with the next asset
                $output->writeln('Could not cancel backup for ' .
                    $asset->getDisplayName() . ': ' . $throwable->getMessage());
            }
        }
    }

    /**
     * Cancel backup for a given asset
     *
     * @param Asset $asset
     */
    private function cancelBackup(Asset $asset): void
    {
        $backupManager = $this->backupManagerFactory->create($asset);
        $backupManager->cancelBackup();
    }
}
