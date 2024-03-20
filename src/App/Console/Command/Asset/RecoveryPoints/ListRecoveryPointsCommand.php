<?php

namespace Datto\App\Console\Command\Asset\RecoveryPoints;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetSummaryService;
use Datto\Asset\RecoveryPoint\FilesystemIntegritySummary;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Cloud\SpeedSync;
use Datto\Resource\DateTimeService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * List all recovery points for a specific asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ListRecoveryPointsCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:recoverypoints:list';

    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    /** @var AssetSummaryService */
    private $assetSummaryService;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        RecoveryPointInfoService $recoveryPointInfoService,
        AssetSummaryService $assetSummaryService,
        DateTimeService $dateTimeService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->assetSummaryService = $assetSummaryService;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('List all recovery points.')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to target.')
            ->addOption('pretty', 'P', InputOption::VALUE_NONE, 'Include pretty printed fields.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $pretty = $input->getOption('pretty');

        $asset = $this->assetService->get($assetKey);

        $this->printSummary($asset, $output);
        $this->printRecoveryPointsList($pretty, $asset, $output);
        return 0;
    }

    /**
     * @param Asset $asset
     * @param OutputInterface $output
     */
    private function printSummary(Asset $asset, OutputInterface $output): void
    {
        $summary = $this->assetSummaryService->getSummary($asset);


        $table = new Table($output);
        $table->setHeaders([
            'Local Used Size',
            'Offsite Used Size',
            'Latest Local Snapshot',
            'Latest Offsite Snapshot',
            'Average Daily Rate of Change',
            'Average Daily Storage Growth (B)',
            'Average Daily Storage Growth (%)'
        ]);

        $table->addRow([
            $summary->getLocalUsedSize(),
            $summary->getOffsiteUsedSize(),
            $summary->getLatestLocalSnapshotEpoch(),
            $summary->getLatestOffsiteSnapshotEpoch(),
            $summary->getRateOfChange(),
            $summary->getRateOfGrowthAbsolute(),
            $summary->getRateOfGrowthPercent()
        ]);

        $table->render();
    }

    /**
     * @param Asset $asset
     * @param OutputInterface $output
     */
    private function printRecoveryPointsList($pretty, Asset $asset, OutputInterface $output): void
    {
        $recoveryPointsInfo = $this->recoveryPointInfoService->getAll($asset);

        $headers = [
            'Snapshot'
        ];

        if ($pretty) {
            $headers[] = 'Snapshot Date';
        }

        $headers = array_merge($headers, [
            'Backup Type',
            'Exists Locally',
            'Exists Offsite',
            'Critical',
            'Restored Locally',
            'Restored Offsite',
            'Used Size',
            'Screenshot Status',
            'Offsite Status',
            'Missing Volumes',
            'Integrity',
            'Forced'
        ]);

        $table = new Table($output);
        $table->setHeaders($headers);

        foreach ($recoveryPointsInfo as $recoveryPointInfo) {
            $row = [
                $recoveryPointInfo->getEpoch()
            ];

            if ($pretty) {
                $row[] = $this->dateTimeService->format('c', $recoveryPointInfo->getEpoch());
            }

            $row = array_merge($row, [
                $recoveryPointInfo->getWorstVolumeBackupType(),
                $recoveryPointInfo->existsLocally() ? 'yes' : 'no',
                $recoveryPointInfo->existsOffsite() ? 'yes' : 'no',
                $recoveryPointInfo->isCritical() ? 'yes' : 'no',
                $recoveryPointInfo->hasLocalRestore() ? 'yes' : 'no',
                $recoveryPointInfo->hasOffsiteRestore() ? 'yes' : 'no',
                $recoveryPointInfo->getLocalUsedSize(),
                $this->getScreenshotStatusText($recoveryPointInfo->getScreenshotStatus()),
                $this->getOffsiteStatusText($recoveryPointInfo->getOffsiteStatus()),
                $this->implodeVolumeMetadataMountPoints($recoveryPointInfo->getMissingVolumes()),
                $this->getFilesystemIntegrityText($recoveryPointInfo->getFilesystemIntegritySummary()),
                $this->getBackupForcedText($recoveryPointInfo->wasBackupForced())
            ]);

            $table->addRow($row);
        }

        $table->render();
    }

    private function getBackupForcedText(bool $wasBackupForced = null): string
    {
        if ($wasBackupForced === null) {
            return '-';
        } elseif ($wasBackupForced) {
            return 'forced';
        } else {
            return 'scheduled';
        }
    }

    /**
     * @param FilesystemIntegritySummary $integritySummary
     * @return string
     */
    private function getFilesystemIntegrityText(FilesystemIntegritySummary $integritySummary): string
    {
        $critical = $integritySummary->getCritical();
        $error = $integritySummary->getError();
        $warning = $integritySummary->getWarning();

        if ($critical) {
            return 'critical (' . $this->implodeVolumeMetadataMountPoints($critical) . ')';
        } elseif ($error) {
            return 'error (' . $this->implodeVolumeMetadataMountPoints($error) . ')';
        } elseif ($warning) {
            return 'warning (' . $this->implodeVolumeMetadataMountPoints($warning) . ')';
        } else {
            return 'healthy';
        }
    }

    /**
     * @param VolumeMetadata[] $volumesMetadata
     * @return string
     */
    private function implodeVolumeMetadataMountPoints(array $volumesMetadata): string
    {
        $toMountPoint = function (VolumeMetadata $volumeMetadata) {
            return $volumeMetadata->getMountpoint();
        };

        return implode(', ', array_map($toMountPoint, $volumesMetadata));
    }

    /**
     * @param int $status
     * @return string
     */
    private function getScreenshotStatusText(int $status): string
    {
        switch ($status) {
            case RecoveryPoint::UNSUCCESSFUL_SCREENSHOT:
                return 'bad';
            case RecoveryPoint::SUCCESSFUL_SCREENSHOT:
                return 'good';
            case RecoveryPoint::SCREENSHOT_INPROGRESS:
                return 'running';
            case RecoveryPoint::SCREENSHOT_QUEUED:
                return 'queued';
            case RecoveryPoint::NO_SCREENSHOT:
            default:
                return '-';
        }
    }

    /**
     * @param int $status
     * @return string
     */
    private function getOffsiteStatusText(int $status): string
    {
        switch ($status) {
            case SpeedSync::OFFSITE_QUEUED:
                return 'queued';
            case SpeedSync::OFFSITE_SYNCING:
                return 'syncing';
            case SpeedSync::OFFSITE_SYNCED:
                return 'synced';
            default:
                return '-';
        }
    }
}
