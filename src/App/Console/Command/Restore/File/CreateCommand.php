<?php

namespace Datto\App\Console\Command\Restore\File;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Encryption\TempAccessService;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\AssetService;
use Datto\Feature\FeatureService;
use Datto\Restore\File\FileRestoreService;
use Datto\Util\ScriptInputHandler;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for creating file restores.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateCommand extends AbstractFileRestoreCommand
{
    protected static $defaultName = 'restore:file:create';

    /** @var AssetService */
    private $assetService;

    /** @var ScriptInputHandler */
    private $inputHelper;

    /** @var TempAccessService */
    private $tempAccessService;

    /** @var EncryptionService */
    private $encryptionService;

    public function __construct(
        AssetService $assetService,
        ScriptInputHandler $inputHelper,
        TempAccessService $tempAccessService,
        EncryptionService $encryptionService,
        FileRestoreService $fileRestoreService
    ) {
        parent::__construct($fileRestoreService);

        $this->assetService = $assetService;
        $this->inputHelper = $inputHelper;
        $this->tempAccessService = $tempAccessService;
        $this->encryptionService = $encryptionService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_FILE];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a file restore')
            ->addArgument('asset', InputArgument::REQUIRED, 'Asset to restore')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot to restore')
            ->addOption('sftp', null, InputOption::VALUE_NONE, 'Allow access over SFTP');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $assetKey = $input->getArgument('asset');
        $agent = $this->assetService->get($assetKey);
        $snapshot = $this->getSnapshot($assetKey, $input->getArgument('snapshot'));
        $withSftp = $input->getOption('sftp');

        $passphrase = $this->promptAgentPassphraseIfRequired(
            $agent,
            $this->tempAccessService,
            $input,
            $output
        );

        $this->fileRestoreService->create($assetKey, $snapshot, $passphrase, $withSftp);
        return 0;
    }

    /**
     * @param $assetKey
     * @param $snapshot
     * @return int
     */
    private function getSnapshot(string $assetKey, $snapshot): int
    {
        if ($snapshot) {
            return $snapshot;
        }

        $asset = $this->assetService->get($assetKey);
        $lastPoint = $asset->getLocal()->getRecoveryPoints()->getLast();

        if ($lastPoint === null) {
            throw new Exception("No recovery points available for $assetKey");
        }

        return $lastPoint->getEpoch();
    }
}
