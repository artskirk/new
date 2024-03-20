<?php

namespace Datto\App\Console\Command\Asset\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Service\Backup\BackupQueueService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue needed backups for assets.
 * This command checks the backup schedule(s) of the asset(s) and
 *  creates the .needsBackup flag if an asset backup should occur.
 * The asset(s) will be queued so it can be backed up by the backup scheduler,
 *  this command does not directly backup any assets.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class BackupScheduleQueueCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:backup:schedule:queue';

    /** @var AssetService */
    private $assetService;

    /** @var BackupQueueService */
    private $backupQueueService;

    public function __construct(
        AssetService $assetService,
        BackupQueueService $backupQueueService
    ) {
        parent::__construct();

        $this->assetService = $assetService;
        $this->backupQueueService = $backupQueueService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_BACKUP_SCHEDULING
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Queue up any assets need to be backed up.')
            ->addArgument('assetKeyName', InputArgument::OPTIONAL, 'The asset to queue up for a backup')
            ->addOption('all', 'A', InputOption::VALUE_NONE, 'Attempt to queue backups for all assets')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force queue backups regardless of schedule');
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('assetKeyName') ?? '';
        $queueAllAssets = $input->getOption('all') ?? false;
        $forceQueue = $input->getOption('force') ?? false;

        if ($assetKeyName && $queueAllAssets) {
            throw new InvalidArgumentException('Cannot use --all option with asset.');
        } elseif ($queueAllAssets) {
            $this->queueAllAssets($forceQueue);
        } elseif ($assetKeyName) {
            $this->queueSingleAsset($assetKeyName, $forceQueue);
        } else {
            throw new InvalidArgumentException('assetKeyName or --all option missing.');
        }
        return 0;
    }

    /**
     * Queue backups for all assets if necessary.
     *
     * @param bool $forceQueue
     */
    private function queueAllAssets(bool $forceQueue): void
    {
        $assets = $this->assetService->getAllActive();
        $this->backupQueueService->queueBackupsForAssets($assets, $forceQueue);
    }

    /**
     * Queue a backup for the given asset if necessary.
     *
     * @param string $assetKeyName
     * @param bool $forceQueue
     */
    private function queueSingleAsset(string $assetKeyName, bool $forceQueue): void
    {
        $asset = $this->assetService->get($assetKeyName);
        $this->backupQueueService->queueBackupsForAssets([$asset], $forceQueue);
    }
}
