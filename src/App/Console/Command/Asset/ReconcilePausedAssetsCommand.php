<?php

namespace Datto\App\Console\Command\Asset;

use Datto\App\Console\Command\AbstractAssetCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\AssetService;
use Datto\Resource\DateTimeService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile assets who have paused backups by checking if there exists a
 * backupPauseUntil key file along side the backupPause key file. If this file
 * exists and the timestamp stored within it is less than the current time
 * we will resume backups for that asset and remove both associated key files.
 *
 * @author Marcus Recck <mr@datto.com>
 */
class ReconcilePausedAssetsCommand extends AbstractAssetCommand
{
    protected static $defaultName = 'asset:reconcile:paused';

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        DateTimeService $dateTimeService,
        CommandValidator $commandValidator,
        AssetService $assetService
    ) {
        parent::__construct($commandValidator, $assetService);

        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription('Check all assets and determine if backups should remain paused.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assets = $this->assetService->getAll();

        foreach ($assets as $asset) {
            $localSettings = $asset->getLocal();

            if (!$localSettings->isPaused()) {
                continue;
            }

            $this->logger->setAssetContext($asset->getKeyName());
            $pauseUntil = $localSettings->getPauseUntil();

            if (is_null($pauseUntil)) {
                $this->logger->info('ARP0001 Backups remain paused indefinitely ...');
            } else {
                $shouldUnpause = $this->dateTimeService->getTime() >= $pauseUntil;

                if ($shouldUnpause) {
                    $this->logger->info('ARP0003 Backup pause threshold has been met, resuming backups ...');
                    $asset->getLocal()->setPaused(false);
                    $this->assetService->save($asset);
                } else {
                    $dateString = $this->dateTimeService->format('Y-m-d h:i:s', $pauseUntil);
                    $this->logger->info('ARP0002 Backups remain paused until', ['pausedUntil' => $dateString]);
                }
            }
        }
        return 0;
    }
}
