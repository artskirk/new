<?php

namespace Datto\App\Console\Command\Asset\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Backup\BackupManager;
use Datto\Backup\BackupManagerFactory;
use Datto\Backup\BackupStatusService;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start a backup of an asset.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupStartCommand extends AbstractCommand implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'asset:backup:start';

    private AgentConfigFactory $agentConfigFactory;
    private AssetService $assetService;
    private FeatureService $featureService;
    private BackupManagerFactory $backupManagerFactory;
    private FileSystem $filesystem;
    private Sleep $sleep;

    public function __construct(
        AgentConfigFactory $agentConfigFactory,
        AssetService $assetService,
        FeatureService $featureService,
        BackupManagerFactory $backupManagerFactory,
        Filesystem $filesystem,
        Sleep $sleep
    ) {
        parent::__construct();
        $this->agentConfigFactory = $agentConfigFactory;
        $this->assetService = $assetService;
        $this->featureService = $featureService;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->filesystem = $filesystem;
        $this->sleep = $sleep;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS
        ];
    }

    protected function configure()
    {
        $this
            ->setDescription('Starts a backup on a provided asset')
            ->addArgument('assetKeyName', InputArgument::REQUIRED, 'The identifier of the asset you wish to backup.')
            ->addOption('metadata', null, InputOption::VALUE_REQUIRED, 'Optional backup metadata. Some asset types may required specific fields.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKeyName = $input->getArgument('assetKeyName');
        $backupMetadata = json_decode($input->getOption('metadata'), true) ?? [];

        $asset = $this->assetService->get($assetKeyName);

        $this->featureService->assertSupported(FeatureService::FEATURE_ASSET_BACKUPS, null, $asset);

        $backupManager = $this->backupManagerFactory->create($asset);
    
        $isDtc = $asset instanceof Agent && $asset->isDirectToCloudAgent();
        if ($isDtc) {
            $this->dtcForceBackup($asset, $backupManager);
        } else {
            $backupManager->startUnscheduledBackup($backupMetadata);
        }
        return 0;
    }

    /**
     * Force a backup to run for cc4pc and cc4azure agents.
     *
     * Temporary hack to make this work by setting a flag.
     * dtcserver/commander will consume the flag if it exists
     * and force a startBackup for the agent.
     *
     * Eventually, if dtcserver/commander ever gets a http
     * server like planned, this can become an API request.
     *
     * @param Asset $asset
     * @param BackupManager $backupManager
     */
    private function dtcForceBackup(Asset $asset, BackupManager $backupManager): void
    {
        // Is a backup already running? If so, don't do anything.
        $backupState = $backupManager->getInfo()->getStatus()->getState();
        if ($backupState !== BackupStatusService::STATE_IDLE) {
            $this->logger->info("CCF0001 Not forcing a backup, because a backup is already running.");
            return;
        }

        $this->agentConfigFactory
            ->create($asset->getKeyName())
            ->set('agentForceBackup', 1);

        // Wait for backup to start.
        $this->logger->debug("CCF0002 Waiting for backup to start...");
        while ($backupState === BackupStatusService::STATE_IDLE) {
            $backupState = $backupManager->getInfo()->getStatus()->getState();
            $this->sleep->sleep(5);
        }

        // Wait for backup to finish
        $this->logger->debug("CCF0003 Waiting for backup to finish...");
        while ($backupState === BackupStatusService::STATE_ACTIVE) {
            $backupState = $backupManager->getInfo()->getStatus()->getState();
            $this->sleep->sleep(5);
        }
        
        $this->logger->info("CCF0004 Forced backup finished. See device log for backup status.");
    }
}
