<?php

namespace Datto\App\Console\Command\Asset\Offsite;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\AssetService;
use Datto\Cloud\OffsiteSnapshotScheduler;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Attempts to send snapshots offsite for any or all assets.
 * This scheduler checks the offsite schedule(s) of the asset(s)
 * to determine which snapshot(s) should be offsited, and
 * starts sends via speedsync
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class OffsiteScheduleRunCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:offsite:schedule:run';

    /** @var OffsiteSnapshotScheduler */
    private $syncService;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        AssetService $assetService,
        OffsiteSnapshotScheduler $syncService
    ) {
        parent::__construct();

        $this->assetService = $assetService;
        $this->syncService = $syncService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_OFFSITE
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Schedule all asset snapshots that need to be offsited.')
            ->addOption('all', 'A', InputOption::VALUE_NONE, 'Runs for all assets')
            ->addArgument('asset', InputArgument::OPTIONAL, 'The asset to run against');
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validate($input);

        if ($input->getOption('all')) {
            $this->runAllAssets();
        } elseif ($input->getArgument('asset')) {
            $assetKeyName = $input->getArgument('asset');
            $this->runSingleAsset($assetKeyName);
        }
        return 0;
    }

    /**
     * throw if invalid args
     * @param $input
     */
    private function validate($input): void
    {
        if ($input->getArgument('asset') && $input->getOption('all')) {
            throw new InvalidArgumentException("Cannot use --all with asset.");
        }

        if (!$input->getArgument('asset') && !$input->getOption('all')) {
            throw new InvalidArgumentException("Asset or all assets must be specified.");
        }
    }

    /**
     * @param string $assetKeyName
     */
    private function runSingleAsset(string $assetKeyName): void
    {
        $asset = $this->assetService->get($assetKeyName);
        $this->syncService->scheduleSnapshots($asset);
    }

    private function runAllAssets(): void
    {
        $this->syncService->scheduleSnapshotsForAllAssets();
    }
}
