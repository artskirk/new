<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\AssetType;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfig;
use Datto\Feature\FeatureService;
use Datto\Samba\SambaManager;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDatasetFactory;
use Datto\ZFS\ZfsDatasetService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to create a legacy share.
 * This should not be used on a production device.
 * This command is intended to be used for testing purposes.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ShareAddLegacyCommand extends AbstractCommand
{
    const SAMBA_WRITE_CACHE_SIZE = '1048576';

    protected static $defaultName = 'share:add:legacy';

    /** @var ZfsDatasetFactory */
    private $zfsDatasetFactory;

    /** @var SambaManager */
    private $sambaManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var SpeedSync */
    private $speedSync;

    private ShareService $shareService;

    public function __construct(
        ZfsDatasetFactory $zfsDatasetFactory,
        SambaManager $sambaManager,
        Filesystem $filesystem,
        SpeedSync $speedSync,
        ShareService $shareService
    ) {
        parent::__construct();

        $this->zfsDatasetFactory = $zfsDatasetFactory;
        $this->sambaManager = $sambaManager;
        $this->filesystem = $filesystem;
        $this->speedSync = $speedSync;
        $this->shareService = $shareService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_SHARE_BACKUPS,
            FeatureService::FEATURE_SHARES,
            FeatureService::FEATURE_SHARES_NAS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription(
                'Create a new legacy share. ' .
                'This command should not be used on a production device as legacy shares are deprecated.'
            )
            ->addArgument('share', InputArgument::REQUIRED, 'Name of the legacy share to be created.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('share');

        $mountpoint = '/home/' . $name;
        $datasetName = 'homePool/home/' . $name;
        $this->createZfsDataset($datasetName, $mountpoint);
        $this->createSambaShare($name, $mountpoint);
        $this->createOffsiteControlFile($name);
        $this->addToSpeedSync($datasetName);
        $this->createAgentInfo($name);

        // Get and re-save to create the other files that the legacy serializers write
        $share = $this->shareService->get($name);
        $this->shareService->save($share);

        return 0;
    }

    /**
     * Create a zfs dataset for the legacy share
     *
     * @param string $datasetName
     * @param string $mountpoint
     *
     * @return void
     */
    private function createZfsDataset(string $datasetName, string $mountpoint): void
    {
        $dataset = $this->zfsDatasetFactory->makePartialDataset($datasetName, $mountpoint);
        $dataset->create();
    }

    /**
     * Create the Samba share
     *
     * @param string $name
     * @param string $mountpoint
     */
    private function createSambaShare(string $name, string $mountpoint): void
    {
        $sambaShare = $this->sambaManager->createShare($name, $mountpoint);

        $shareProperties = [
            'public' => 'yes',
            'guest ok' => 'yes',
            'valid users' => '',
            'admin users' => '',
            'writable' => 'yes',
            'force user' => 'root',
            'force group' => 'root',
            'create mask' => '777',
            'directory mask' => '777',
            'write cache size' => self::SAMBA_WRITE_CACHE_SIZE,
            'browsable' => 'no'
        ];

        $sambaShare->setProperties($shareProperties);
        $this->sambaManager->sync();
    }

    /**
     * Create the offsite control file for the legacy share.
     *
     * @param string $name
     */
    private function createOffsiteControlFile(string $name): void
    {
        $keyBase = AgentConfig::BASE_KEY_CONFIG_PATH . '/';
        $offsiteControlFile = $keyBase . $name . ".offsiteControl";

        if (!$this->filesystem->exists($offsiteControlFile)) {
            $defaultControlSettings = json_encode(
                ['interval' => "86400", 'latestSnapshot' => "0", 'latestOffsiteSnapshot' => "0"]
            );
            $this->filesystem->filePutContents($offsiteControlFile, $defaultControlSettings);
        }
    }

    /**
     * Add the legacy share to SpeedSync.
     *
     * @param string $datasetName
     */
    private function addToSpeedSync(string $datasetName): void
    {
        $this->speedSync->add($datasetName, SpeedSync::TARGET_CLOUD);
    }

    /**
     * Add the minimal set of fields to the agentInfo so it can be detected as a zfs share correctly
     *
     * @param string $name
     */
    private function createAgentInfo(string $name): void
    {
        $this->filesystem->filePutContents(AgentConfig::BASE_KEY_CONFIG_PATH . "/$name.agentInfo", serialize(
            [
                'name' => $name,
                'type' => 'snapnas',
                'shareType' => AssetType::ZFS_SHARE
            ]
        ));
    }
}
