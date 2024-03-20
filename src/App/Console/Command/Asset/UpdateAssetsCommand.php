<?php

namespace Datto\App\Console\Command\Asset;

use Datto\Asset\AssetInfoSyncService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Used to send asset info to device-web. This replaced `snapctl updateVols`.
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class UpdateAssetsCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'asset:update:cloud';

    const MIN_SEC_BETWEEN_SENDS = 900; // 15 min
    const LAST_SENT_TIME_FILE = '/dev/shm/assetInfoLastSentTime';

    /** @var AssetInfoSyncService */
    private $assetInfoSyncService;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    public function __construct(
        AssetInfoSyncService $assetInfoSyncService,
        Filesystem $filesystem,
        DateTimeService $dateTimeService
    ) {
        parent::__construct();

        $this->assetInfoSyncService = $assetInfoSyncService;
        $this->filesystem = $filesystem;
        $this->dateTimeService = $dateTimeService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Send asset information to the cloud. Only sends changes since the last call.')
            ->addOption('force', 'F', InputOption::VALUE_NONE, 'Force an update now, even if there was an update recently.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        if (!$force && $this->filesystem->exists(self::LAST_SENT_TIME_FILE)) {
            $lastSentTime = (int)$this->filesystem->fileGetContents(self::LAST_SENT_TIME_FILE);
            $currentTime = $this->dateTimeService->getTime();
            $secSinceLastSend = $currentTime - $lastSentTime;

            if ($secSinceLastSend < self::MIN_SEC_BETWEEN_SENDS) { // We don't want to spam device-web
                $timeLeft = self::MIN_SEC_BETWEEN_SENDS - $secSinceLastSend;
                $this->logger->debug('AIS0001 Asset info was synced recently. Must wait before syncing again.', ['waitTimeLeft' => $timeLeft]);
                return 0;
            }
        }

        try {
            if ($this->assetInfoSyncService->sync()) {
                $this->logger->info('AIS0004 Asset info synced successfully. Writing last sent file');
                $this->filesystem->filePutContents(self::LAST_SENT_TIME_FILE, $this->dateTimeService->getTime());
            } else {
                $this->logger->debug("AIS0005 No asset info changes to sync.");
            }
            return 0;
        } catch (Throwable $e) {
            $this->logger->error('AIS0003 Exception encountered during update assets command', ['error' => $e->getMessage()]);
            $this->filesystem->filePutContents(self::LAST_SENT_TIME_FILE, $this->dateTimeService->getTime());
            return 1;
        }
    }
}
