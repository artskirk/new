<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Resource\DateTimeService;
use Datto\Service\Retention\Exception\RetentionCannotRunException;
use Datto\Service\Retention\RetentionFactory;
use Datto\Service\Retention\RetentionService;
use Datto\Service\Retention\RetentionType;
use Datto\Service\Retention\Strategy\RetentionStrategyInterface;
use Datto\Util\DateTimeZoneService;
use Datto\Utility\ByteUnit;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RetentionCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:retention';

    /**
     * @var FeatureService
     */
    private $featureService;

    /**
     * @var RetentionFactory
     */
    private $retentionFactory;

    /**
     * @var RetentionService
     */
    private $retentionService;

    /**
     * @var DateTimeZoneService
     */
    private $dateTimeZoneService;

    /**
     * @var DateTimeService
     */
    private $dateTimeService;

    public function __construct(
        FeatureService $featureService,
        RetentionFactory $retentionFactory,
        RetentionService $retentionService,
        DateTimeZoneService $dateTimeZoneService,
        DateTimeService $dateTimeService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->featureService = $featureService;
        $this->retentionFactory = $retentionFactory;
        $this->retentionService = $retentionService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Run retention for assets')
            ->addArgument('assetKey', InputArgument::OPTIONAL, 'Assets to run retention for (default: all)')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Returns the points that will be removed if retention is run, without actually removing them'
            )
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run retention for all assets')
            ->addOption('nightly', null, InputOption::VALUE_NONE, 'Use nightly retention limits')
            ->addOption('local', 'l', InputOption::VALUE_NONE, 'Run local retention')
            ->addOption('offsite', 'o', InputOption::VALUE_NONE, 'Run offsite retention');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = (bool) $input->getOption('dry-run');
        $isNightly = (bool) $input->getOption('nightly');
        $retentions = $this->getRetentions($input);

        foreach ($retentions as $retention) {
            try {
                $this->executeRetention($retention, $output, $isDryRun, $isNightly);
            } catch (RetentionCannotRunException $ex) {
            }
        }

        return 0;
    }

    private function executeRetention(
        RetentionStrategyInterface $retention,
        OutputInterface $output,
        bool $isDryRun,
        bool $isNightly
    ): void {
        $assetKeyName = $retention->getAsset()->getKeyName();
        $description = ucfirst($retention->getDescription());

        if ($isDryRun) {
            $output->writeln("${description} retention results for ${assetKeyName}.");
            $removableSnapshotsInfo = $this->retentionService->dryRunRetention($retention);
            $this->displayHumanReadable($output, $removableSnapshotsInfo);
        } else {
            $this->retentionService->doRetention($retention, $isNightly);
            $output->writeln("${description} retention for ${assetKeyName} complete.");
        }
    }

    /**
     * Display a list of epochs and sizes of snapshots which would be removed by retention.
     *
     * @param OutputInterface $output CLI output interface
     * @param array[] $removableSnapshotsInfo Epochs and sizes of snapshots which would be removed with retention
     */
    private function displayHumanReadable(OutputInterface $output, array $removableSnapshotsInfo)
    {
        $numSnapshotsToRemove = count($removableSnapshotsInfo);
        if ($numSnapshotsToRemove === 0) {
            $output->writeln('There are no available Snapshots queued for deletion.');
            return;
        }

        $output->writeln('Snapshots that will be deleted due to Retention:');

        $dateFormat = $this->dateTimeZoneService->localizedDateFormat('date-time-short');
        foreach ($removableSnapshotsInfo as $snapshotInfo) {
            $date = $this->dateTimeService->format($dateFormat, $snapshotInfo['time'] ?? 0);
            $size = round(ByteUnit::BYTE()->toGiB($snapshotInfo['size'] ?? 0), 2);

            $output->writeln("$date @ $size GB");
        }

        $output->writeln("Total amount of snapshots to be removed: $numSnapshotsToRemove");
    }

    /**
     * @param InputInterface $input
     *
     * @return RetentionStrategyInterface[]
     */
    private function getRetentions(InputInterface $input): array
    {
        $retentions = [];

        $assets = $this->getAssets($input);
        $types = $this->getRetentionType($input);

        foreach ($assets as $asset) {
            foreach ($types as $type) {
                $retentions[] = $this->retentionFactory->create($asset, $type);
            }
        }

        return $retentions;
    }

    /**
     * @param InputInterface $input
     *
     * @return RetentionType[]
     */
    private function getRetentionType(InputInterface $input): array
    {
        $types = [];

        if ($input->getOption('local')) {
            $types[] = RetentionType::LOCAL();
            $this->featureService->assertSupported(FeatureService::FEATURE_LOCAL_RETENTION);
        }

        if ($input->getOption('offsite')) {
            $types[] = RetentionType::OFFSITE();
            $this->featureService->assertSupported(FeatureService::FEATURE_OFFSITE_RETENTION);
        }

        if (empty($types)) {
            throw new \RuntimeException('Please select at least one retention type: --local or --offsite');
        }

        return $types;
    }

    protected function getAssets(InputInterface $input): array
    {
        $assetKey = $input->getArgument('assetKey');
        $all = $input->getOption('all');

        if (!$all && !$assetKey) {
            throw new \RuntimeException('Please provide assetKey or use option --all');
        }

        if ($all && $assetKey) {
            throw new \RuntimeException('The --all option can be used only when assetKey argument is not provided');
        }

        if ($all) {
            return $this->assetService->getAll();
        } else {
            return [$this->assetService->get($assetKey)];
        }
    }
}
