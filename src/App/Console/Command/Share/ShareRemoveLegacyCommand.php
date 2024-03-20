<?php

namespace Datto\App\Console\Command\Share;

use Datto\App\Console\Command\AbstractCommand;
use Datto\App\Console\Input\InputArgument;
use Datto\Cloud\SpeedSync;
use Datto\Config\AgentConfig;
use Datto\Feature\FeatureService;
use Datto\Samba\SambaManager;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to remove a legacy share.
 * This should not be used on a production device.
 * This command is intended to be used for testing purposes.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ShareRemoveLegacyCommand extends AbstractCommand
{
    protected static $defaultName = 'share:remove:legacy';

    /** @var ZfsService */
    private $zfsService;

    /** @var SambaManager */
    private $sambaManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var SpeedSync */
    private $speedSync;

    public function __construct(
        ZfsService $zfsService,
        SambaManager $sambaManager,
        Filesystem $filesystem,
        SpeedSync $speedSync
    ) {
        parent::__construct();

        $this->zfsService = $zfsService;
        $this->sambaManager = $sambaManager;
        $this->filesystem = $filesystem;
        $this->speedSync = $speedSync;
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
                'Delete a new legacy share. ' .
                'This command should not be used on a production device.'
            )
            ->addArgument('share', InputArgument::REQUIRED, 'Name of the legacy share to be deleted.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('share');

        $mountpoint = '/home/' . $name;
        $datasetName = 'homePool/home/' . $name;
        $this->removeFromSpeedSync($datasetName);
        $this->removeKeyFiles($name);
        $this->removeSambaShare($name);
        $this->destroyZfsDataset($datasetName, $mountpoint);
        return 0;
    }

    /**
     * Remove the legacy share from SpeedSync.
     *
     * @param string $datasetName
     */
    private function removeFromSpeedSync(string $datasetName): void
    {
        $this->speedSync->remove($datasetName);
    }

    /**
     * Remove the keys files associated with the legacy share.
     *
     * @param string $name
     */
    private function removeKeyFiles(string $name): void
    {
        $keyBase = AgentConfig::BASE_KEY_CONFIG_PATH . '/';
        $keyFilesRegex = $keyBase . $name . ".*";
        $keyFiles = $this->filesystem->glob($keyFilesRegex);
        foreach ($keyFiles as $keyFile) {
            $this->filesystem->unlink($keyFile);
        }
    }
    /**
     * Remove the samba share.
     *
     * @param string $name
     */
    private function removeSambaShare(string $name): void
    {
        $this->sambaManager->removeShare($name);
    }

    /**
     * Destroy the zfs dataset for the legacy share
     *
     * @param string $datasetName
     * @param string $mountpoint
     */
    private function destroyZfsDataset(string $datasetName, string $mountpoint): void
    {
        $this->zfsService->destroyDataset($datasetName, $recursive = true);
        $this->filesystem->unlinkDir($mountpoint);
    }
}
