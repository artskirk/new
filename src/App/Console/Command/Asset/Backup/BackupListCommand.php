<?php

namespace Datto\App\Console\Command\Asset\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\AssetService;
use Datto\Backup\BackupInfo;
use Datto\Backup\BackupManagerFactory;
use Datto\Backup\BackupStatusService;
use Datto\Feature\FeatureService;
use Datto\Utility\ByteUnit;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list all assets with running backups on the device
 *
 * @author Charles Shapleigh <cshapleigh@gmail.com>
 */
class BackupListCommand extends AbstractCommand
{
    protected static $defaultName = 'asset:backup:list';

    /** @var BackupManagerFactory */
    private $factory;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        BackupManagerFactory $factory,
        AssetService $assetService
    ) {
        parent::__construct();

        $this->factory = $factory;
        $this->assetService = $assetService;
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
            ->setDescription('Lists all running backups');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assets = $this->assetService->getAll();

        $table = new Table($output);

        $table->setHeaders([
            'Key',
            'Display',
            'Status'
        ]);

        foreach ($assets as $asset) {
            $manager = $this->factory->create($asset);
            $info = $manager->getInfo();

            $table->addRow([
                $asset->getKeyName(),
                $asset->getDisplayName(),
                $this->humanize($info)
            ]);
        }

        $table->render();
        return 0;
    }

    private function humanize(BackupInfo $info): string
    {
        if ($info->isQueued()) {
            return 'queued';
        } else {
            $status = $info->getStatus();
            $state = $status->getState();
            $additional = $status->getAdditional();

            if ($state === BackupStatusService::STATE_TRANSFER) {
                if ($additional['step'] === BackupStatusService::STATE_TRANSFER_STEP_TRANSFERRING) {
                    $sent = $additional['sent'];
                    $total = $additional['total'];
                    $percentage = ($sent / $total) * 100;

                    $runtime = $additional['time'] - $additional['transferStart'];
                    $speed = $runtime <= 0 ? 0 : ($sent / $runtime);

                    return sprintf(
                        "%d/%d MiB (%d%%, %d MiB/s)",
                        round(ByteUnit::BYTE()->toMiB($sent)),
                        round(ByteUnit::BYTE()->toMiB($total)),
                        round($percentage, 1),
                        round(ByteUnit::BYTE()->toMiB($speed), 1)
                    );
                } else {
                    return sprintf("%s (%s)", $state, $additional['step']);
                }
            }
            return $state;
        }
    }
}
