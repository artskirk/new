<?php

namespace Datto\App\Console\Command\System\OwnCloud;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\CommandValidator;
use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\Nas\NasShareBuilderFactory;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\OwnCloud\OwnCloud;
use Datto\OwnCloud\OwnCloudStorage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * As part of sunsetting DattoDrive/Owncloud, moves user data from DattoDrive/Owncloud to NasShare.
 * A NasShare per user is created.
 * Deletes offsite data if present.
 * Deletes datto drive.
 */
class OwnCloudMigrateToNasCommand extends AbstractShareCommand
{
    protected static $defaultName = 'system:owncloud:migrateToNas';
    const OWNCLOUD_MOUNT_PATH = '/datto/owncloud';
    const OWNCLOUD_DATASET = 'homePool/home/owncloud';

    private ProcessFactory $processFactory;
    private Filesystem $filesystem;
    private CreateShareService $createShareService;
    private NasShareBuilderFactory $nasShareBuilderFactory;
    private OwnCloudStorage $ownCloudStorage;
    private OwnCloud $ownCloud;
    private SpeedSync $speedSync;

    public function __construct(
        CreateShareService     $createShareService,
        CommandValidator       $commandValidator,
        ShareService           $shareService,
        NasShareBuilderFactory $nasShareBuilderFactory,
        ProcessFactory         $processFactory,
        Filesystem             $filesystem,
        OwnCloudStorage        $ownCloudStorage,
        OwnCloud               $ownCloud,
        SpeedSync              $speedSync
    ) {
        parent::__construct($commandValidator, $shareService);
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->nasShareBuilderFactory = $nasShareBuilderFactory;
        $this->createShareService = $createShareService;
        $this->ownCloudStorage = $ownCloudStorage;
        $this->ownCloud = $ownCloud;
        $this->speedSync = $speedSync;
    }

    protected function configure(): void
    {
        $this->setDescription('Migrate DattoDrive/Owncloud data to NAS shares per user as part of sunsetting DattoDrive.')
            ->addOption('prefix', 'p', InputOption::VALUE_OPTIONAL, 'Share prefix to be prepended to shares', 'dattodrv')
            ->addOption('size', 'S', InputOption::VALUE_OPTIONAL, 'Size of this share', Share::DEFAULT_MAX_SIZE)
            ->addOption('offsiteTarget', 'o', InputOption::VALUE_OPTIONAL, 'This specifies the target for offsiting new NasShares. Can be "cloud", "noOffsite", or a device ID for peer to peer.', SpeedSync::TARGET_CLOUD);
    }

    private function createNasShareFromParams(string $name, string $size, string $offsiteTarget): Share
    {
        $builder = $this->nasShareBuilderFactory->create($name);
        $nasShare = $builder
            ->format(NasShare::DEFAULT_FORMAT)
            ->offsite($this->createShareService->createDefaultOffsiteSettings())
            ->originDevice($this->createShareService->createOriginDevice())
            ->offsiteTarget($offsiteTarget)
            ->build();

        return $this->createShareService->create($nasShare, $size);
    }

    /**
     * Display the results in tabular format.
     */
    private function renderShareResults(OutputInterface $output, array $addedShares): void
    {
        $renderTable = new Table($output);
        $headers = [
            'Share name',
            'Key',
            'Status'
        ];

        $renderTable->setHeaders($headers);
        foreach ($addedShares as $shareName => $val) {
            $rowData = [
                $shareName,
                $val['key'],
                $val['status']
            ];

            $renderTable->addRow($rowData);
        }
        $renderTable->render();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retCode = 0;

        $prefix = $input->getOption('prefix');
        $shareSize = $input->getOption('size') ?? Share::DEFAULT_MAX_SIZE;
        $offsiteTarget = $input->getOption('offsiteTarget') ?? SpeedSync::TARGET_CLOUD;

        if (!$this->ownCloudStorage->hasStorageAllocated()) {
            $output->writeln('Did not find Dattodrive/Owncloud data on the device');
            return 0;
        }

        $filesList = $this->filesystem->glob(self::OWNCLOUD_MOUNT_PATH . "/data/*/files") ?: [];
        $userNames = array_map(
            //returns 'user' from /datto/owncloud/data/user/files
            fn($fileName) => preg_replace('/\\/datto\/owncloud\/data\/([^\/]+)\/files.*/', "$1", $fileName, 1),
            $filesList
        );

        if (count($userNames)) {
            $this->logger->info("OCM0001 DattoDrive data found on the device");
        }

        $addedShares = [];
        foreach ($userNames as $user) {
            $userMoveSucceeded = true;
            $nasName = $prefix . $user;
            $this->logger->info("OCM0002 Creating share", ['user' => $user, 'nasName' => $nasName]);
            $share = $this->createNasShareFromParams($nasName, $shareSize, $offsiteTarget);
            $addedShares[$nasName]['key'] = $share->getKeyName();

            // Move files for user from owncloud to new share.
            $nasDestDir = "/datto/mounts/$nasName";
            $sourceList = $this->filesystem->glob(self::OWNCLOUD_MOUNT_PATH . "/data/$user/files/*") ?: [];
            foreach ($sourceList as $srcEntry) {
                $process = $this->processFactory->get(["/usr/bin/mv", "$srcEntry", "$nasDestDir/"]);
                if ($process->run() !== 0) {
                    $userMoveSucceeded = false;
                    $this->logger->critical("OCM0003 Moving user data to NasShare failed", [
                        'user' => $user,
                        'source' => $srcEntry,
                        'destination' => $nasDestDir,
                        'error' => $process->getErrorOutput()
                    ]);
                    $addedShares[$nasName]['status'] = 'move failed';
                }
            }

            if ($userMoveSucceeded) {
                // Delete Owncloud user directory.
                $this->ownCloudStorage->deleteUserData($user);
                $addedShares[$nasName]['status'] = 'success';
            }
        }

        $remainingUserDirs = $this->filesystem->glob(self::OWNCLOUD_MOUNT_PATH . "/data/*/files") ?: [];
        if (count($remainingUserDirs)) {
            $this->logger->warning('OCM0004 Not deleting owncloud, user directories remain', [
                'remaining' => var_export($remainingUserDirs, true)
            ]);
            $retCode = 1;
        } else {
            $offsitePoints = $this->speedSync->getOffsitePoints(self::OWNCLOUD_DATASET);
            if (count($offsitePoints)) {
                if (!$this->ownCloudStorage->destroyOffsiteStorage()) {
                    // Remote destroy fails randomly, do a speedsync refresh to fix it.
                    $this->speedSync->refresh(self::OWNCLOUD_DATASET);

                    $offsitePoints = $this->speedSync->getOffsitePoints(self::OWNCLOUD_DATASET);
                    if (count($offsitePoints)) {
                        $this->logger->error("OCM0005 Destroying offsite storage for DattDrive/OwnCloud failed!");
                    }
                }
            }
            $this->ownCloud->purge();
        }

        $output->writeln("Created shares");
        $this->renderShareResults($output, $addedShares);

        if ($retCode === 0) {
            $this->logger->info("OCM0006 DattoDrive migrated to NasShare on the device.");
        }

        return $retCode;
    }
}
