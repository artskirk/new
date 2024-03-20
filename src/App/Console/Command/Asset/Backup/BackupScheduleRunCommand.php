<?php

namespace Datto\App\Console\Command\Asset\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Service\Backup\BackupScheduleRunService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attempts to backup any or all assets.
 * This scheduler checks for the existence of the .needsBackup flag to determine if the asset(s) should be backed up.
 * The asset(s) .needsBackup flag will be cleared and a backup will be run in a separate screen.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class BackupScheduleRunCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:backup:schedule:run';

    /** @var BackupScheduleRunService */
    private $backupScheduleRunService;

    public function __construct(
        BackupScheduleRunService $backupScheduleRunService
    ) {
        parent::__construct();

        $this->backupScheduleRunService = $backupScheduleRunService;
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
            ->setDescription('Run scheduled backups for any assets.')
            ->addArgument('assetKeyName', InputArgument::OPTIONAL, 'The asset to back up')
            ->addOption('all', 'A', InputOption::VALUE_NONE, 'Attempt to run scheduled backups for all assets');
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('assetKeyName') ?? '';
        $scheduleAllAssets = $input->getOption('all') ?? false;

        if ($assetKeyName && $scheduleAllAssets) {
            throw new InvalidArgumentException('Cannot use --all option with asset.');
        } elseif ($scheduleAllAssets) {
            $this->backupScheduleRunService->scheduleBackupsForAllAssets();
        } elseif ($assetKeyName) {
            $this->backupScheduleRunService->runScheduledBackupForAsset($assetKeyName);
        } else {
            throw new InvalidArgumentException('assetKeyName or --all option missing.');
        }
        return 0;
    }
}
