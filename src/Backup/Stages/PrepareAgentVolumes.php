<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Agentless\AgentlessSystem;
use Datto\Asset\Agent\AgentPlatform;
use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Asset\Agent\Backup\DiskDrive;
use Datto\Asset\Agent\Backup\DiskDriveFactory;
use Datto\Asset\Agent\Backup\Serializer\DiskDriveSerializer;
use Datto\Asset\Agent\DiffMergeService;
use Datto\Asset\Agent\DmCryptManager;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\Agent\VolumesCollector;
use Datto\Asset\Agent\VolumesService;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Asset\BackupConstraintsService;
use Datto\Backup\BackupStatusService;
use Datto\Backup\File\BackupImageFile;
use Datto\Feature\FeatureService;
use Datto\Filesystem\PartitionService;
use Datto\Filesystem\SparseFileService;
use Datto\Util\Email\EmailService;
use Datto\Util\Email\Generator\VolumeValidationEmailGenerator;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Datto\Util\OsFamily;
use Exception;
use Throwable;

/**
 * This backup stage determines which volumes to include,
 * writes out the volume table file to the live dataset, and
 * ensures that volume and checksum sparse files exist.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class PrepareAgentVolumes extends BackupStage
{
    /** Extension for checksum file for each volume */
    const CHECKSUM_EXTENSION = "checksum";

    const FULL_BACKUP_ESX = '*';

    const MBR_MAX_SIZE_TIB = 2;

    const SHADOW_SNAP_RESERVED_MIB = 32;

    const EXCLUDED_MOUNTPOINT = '<swap>';

    const CHANGE_ID_FILE_SIZE_BYTES = 512;
    const CHANGE_ID_SECTOR_SIZE_BYTES = 512;

    /** @var Filesystem */
    private $filesystem;

    /** @var EncryptionService */
    private $encryptionService;

    /** @var DmCryptManager */
    private $dmCryptManager;

    /** @var SparseFileService */
    private $sparseFileService;

    /** @var DiskDriveSerializer */
    private $diskDriveSerializer;

    /** @var DiskDriveFactory */
    private $diskDriveFactory;

    /** @var BackupConstraintsService */
    private $backupConstraintsService;

    /** @var FeatureService */
    private $featureService;

    /** @var EmailService  */
    private $emailService;

    /** @var VolumeValidationEmailGenerator  */
    private $volumeValidationEmailGenerator;

    private DiffMergeService $diffMergeService;
    private VolumesService $volumesService;
    private VolumesCollector $volumesCollector;

    private AssetService $assetService;

    public function __construct(
        Filesystem $filesystem,
        EncryptionService $encryptionService,
        DmCryptManager $dmCryptManager,
        SparseFileService $sparseFileService,
        DiskDriveSerializer $diskDriveSerializer,
        DiskDriveFactory $diskDriveFactory,
        BackupConstraintsService $backupConstraintsService,
        FeatureService $featureService,
        EmailService $emailService,
        VolumeValidationEmailGenerator $volumeValidationEmailGenerator,
        DiffMergeService $diffMergeService,
        VolumesService $volumesService,
        VolumesCollector $volumesCollector,
        AssetService $assetService
    ) {
        $this->filesystem = $filesystem;
        $this->encryptionService = $encryptionService;
        $this->dmCryptManager = $dmCryptManager;
        $this->sparseFileService = $sparseFileService;
        $this->diskDriveSerializer = $diskDriveSerializer;
        $this->diskDriveFactory = $diskDriveFactory;
        $this->backupConstraintsService = $backupConstraintsService;
        $this->featureService = $featureService;
        $this->emailService = $emailService;
        $this->volumeValidationEmailGenerator = $volumeValidationEmailGenerator;
        $this->diffMergeService = $diffMergeService;
        $this->volumesService = $volumesService;
        $this->volumesCollector = $volumesCollector;
        $this->assetService = $assetService;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $asset = $this->context->getAsset();
        $this->context->updateBackupStatus(BackupStatusService::STATE_QUERY);
        $this->determineVolumeInformation();
        /** @var Agent $asset */
        if ($asset->isType(AssetType::AGENT) && !$asset->isSupportedOperatingSystem()) {
            $this->determineFullDiskInformation();
        }

        // Want to make sure we only run this during prepare and for agents that support backup constraints
        if (!$this->context->shouldSkipVolumeValidation() &&
            $this->featureService->isSupported(FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS, null, $asset)
        ) {
            $this->validateVolumeConfiguration();
        }

        $this->writeVolumeTable();
        $this->writeDiskTable();
        $this->prepareVolumes();
        $this->context->reloadAsset();
    }

    /**
     * @inheritdoc
     * In case an exception was thrown with regards to creation of the partition table (sgdisk/gdisk),
     * delete the entire .datto so the partition table is written again with the correct info
     * on the next backup (BCDR-27237)
     */
    public function rollback(): void
    {
        $this->context->getLogger()->info('BAK2042 Failed to complete stage PrepareAgentVolumes, rolling back');
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        if ($agent->isForcePartitionRewrite()) {
            $this->context->getLogger()
                ->error(
                    'BAK2045 Failed to force partition rewrite',
                    ['agentKey' => $agent->getKeyName()]
                );
            $this->context->setFailedForcePartitionRewrite(true);
        }
        $this->cleanup();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        if ($agent->isForcePartitionRewrite() && !$this->context->isFailedForcePartitionRewrite()) {
            $agent->setForcePartitionRewrite(false);
            $this->assetService->save($agent);
        }

        $isEncrypted = $agent->getEncryption()->isEnabled();
        if ($isEncrypted) {
            $this->context->getLogger()->debug('BAK4000 Cleaning up DmCrypt devices.');
            $imageDevices = $this->context->getImageLoopsOrFiles();
            foreach ($imageDevices as $imageDevice) {
                try {
                    $this->dmCryptManager->detach($imageDevice);
                    $this->context->getLogger()->debug('BAK4001 DmCrypt device successfully detached.');
                } catch (Throwable $throwable) {
                    $this->context->getLogger()->error(
                        'BAK4002 There was a problem detaching the DmCrypt device.',
                        ['exception' => $throwable]
                    );
                }
            }
        }
    }

    private function removeBotchedImageFile(string $imageFilePath): void
    {
        $this->context->getLogger()
            ->info(
                'BAK4003 Attempting to delete sparse image file that failed to partition',
                ['imageFilePath' => $imageFilePath]
            );
        if (!$this->filesystem->unlink($imageFilePath)) {
            $this->context->getLogger()
                ->error(
                    'BAK2043 Failed to delete sparse image file that failed to partition',
                    ['imageFile' => $imageFilePath]
                );
        }
    }

    /**
     * Determine which volumes should be included in the backup.
     * Updates the context with the volume information.
     */
    private function determineVolumeInformation()
    {
        $includedVolumeGuids = [];
        $allVolumes = [];

        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $includedVolumes = $agent->getIncludedVolumesSettings()->getIncludedList();

        if ($agent->getPlatform() !== AgentPlatform::AGENTLESS_GENERIC() && count($includedVolumes) === 0) {
            $this->context->getLogger()->critical('BAK0510 No volumes have been selected for backup');
            throw new Exception('No volumes have been selected for backup');
        }

        foreach ($includedVolumes as $includedVolumeGuid) {
            try {
                $volume = $agent->getVolume($includedVolumeGuid);
                if ($volume->getMountpoint() === self::EXCLUDED_MOUNTPOINT) {
                    continue;
                }
                $includedVolumeGuids[] = $includedVolumeGuid;
                $allVolumes[$includedVolumeGuid] = $volume->toArray();
            } catch (\Throwable $t) {
                // continue with loop, do not throw because a volume becomes missing
            }
        }

        if (!$agent->isType(AssetType::AGENTLESS_GENERIC) && empty($includedVolumeGuids)) {
            // all included volumes are missing should also trigger what is essentially no volumes in the backup alert
            $this->context->getLogger()->warning(
                'BAK0511 All included volumes are missing',
                [
                    'Volumes' => $agent->getVolumes(),
                    'includedVolumeGuids' => $includedVolumes
                ]
            );
            $this->context->getLogger()->critical('BAK0510 No volumes have been selected for backup');
            throw new Exception('No volumes have been selected for backup');
        }
        $this->context->clearAlert('BAK0510');

        $this->context->setIncludedVolumeGuids($includedVolumeGuids);
        $this->context->setAllVolumes($allVolumes);
    }

    /**
     * Update the context with which disks will be included in the backup.
     */
    private function determineFullDiskInformation()
    {
        /** @var AgentlessSystem $agent */
        $agent = $this->context->getAsset();

        $vmdkInfo = $agent->getEsxInfo()->getVmdkInfo();

        if (!is_array($vmdkInfo) || count($vmdkInfo) === 0) {
            $this->context->getLogger()->critical('BAK0510 No volumes have been selected for backup');
            throw new Exception("Unable to retrieve VMDK information");
        }
        $this->context->clearAlert('BAK0510');

        $vmdkDisks = $this->diskDriveFactory->createDiskDrivesFromVmdkInfo($vmdkInfo);

        $this->updateVolumeDiskGuids($vmdkInfo);

        $this->context->setIncludedDiskGuids(array_keys($vmdkDisks));
        $this->context->setAllDisks($vmdkDisks);
    }

    /**
     * Validate volume configuration based on backup constraints
     */
    private function validateVolumeConfiguration()
    {
        $agent = $this->context->getAsset();
        // validate volume configuration
        $result = $this->backupConstraintsService->enforce($agent);
        if (!$result->getMaxTotalVolumeResult()) {
            $this->emailService->sendEmail($this->volumeValidationEmailGenerator->generate($agent, $result));

            $this->context->getLogger()->error('BAK0800 Volume validation failed.', [
                'agentUuid' => $agent->getKeyName(),
                'message' => $result->getMaxTotalVolumeMessage()
            ]);

            throw new \Exception('Agent volume validation failed, cannot prepare for backup.');
        }
    }

    private function updateVolumeDiskGuids(array $vmdkInfo)
    {
        $partitionToDiskGuidMap = [];

        foreach ($vmdkInfo as $disk) {
            $partitions = $disk['partitions'] ?? [];
            $diskGuid = $disk['diskUuid'] ?? '';
            foreach ($partitions as $partition) {
                $partitionGuid = $partition['guid'] ?? '';
                $partitionToDiskGuidMap[$partitionGuid] = $diskGuid;
            }
        }

        $allVolumes = $this->context->getAllVolumes();

        foreach ($partitionToDiskGuidMap as $partitionGuid => $diskGuid) {
            if (isset($allVolumes[$partitionGuid])) {
                $allVolumes[$partitionGuid]['diskUuid'] = $diskGuid;
            }
        }

        $this->context->setAllVolumes($allVolumes);
    }

    /**
     * Write the volume information to the voltab file
     */
    private function writeVolumeTable()
    {
        $includedVolumeSettings = new IncludedVolumesSettings($this->context->getIncludedVolumeGuids());
        $allVolumes = $this->volumesCollector->collectVolumesFromAssocArray($this->context->getAllVolumes());
        $voltab = $this->volumesService->generateVoltabArray($allVolumes, $includedVolumeSettings);
        $imageDir = $this->context->getAsset()->getDataset()->getAttribute('mountpoint');
        $path = $imageDir . '/' . AgentSnapshotRepository::KEY_VOLTAB_TEMPLATE;
        $content = json_encode($voltab);
        $this->filesystem->put($path, $content);
    }

    private function writeDiskTable()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        if (!$agent->isSupportedOperatingSystem()) {
            $disks = $this->context->getAllDisks();
            $imageDir = $agent->getDataset()->getAttribute('mountpoint');
            $path = sprintf(
                '%s/' . AgentSnapshotRepository::KEY_DISKTAB_TEMPLATE,
                $imageDir,
                $agent->getKeyName()
            );
            $content = $this->diskDriveSerializer->serialize($disks);
            $this->filesystem->filePutContents($path, $content);
        }
    }

    /**
     * Prepares the volumes.
     * Ensures that image and checksum files are created.
     * Creates dmCrypt for encrypted agents.
     */
    private function prepareVolumes()
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $imageDir = $agent->getDataset()->getAttribute('mountpoint');

        if ($agent->isSupportedOperatingSystem()) {
            $imageLoopsOrFiles = $this->prepareVolumeImages($imageDir);
        } else {
            $imageLoopsOrFiles = $this->prepareDiskImages($imageDir);
        }

        $checksumFiles = [];
        if ($this->context->hasChecksumFile()) {
            foreach ($imageLoopsOrFiles as $guid => $file) {
                $checksumFiles[$guid] = $this->getChecksumFilename($imageDir, $guid);
            }
        }

        $this->context->setImageLoopsOrFiles($imageLoopsOrFiles);

        if ($this->context->hasChecksumFile()) {
            $this->context->setChecksumFiles($checksumFiles);
        }
    }

    /**
     * @param string $imageDir
     * @return string[] Filename array indexed by guid
     */
    private function prepareVolumeImages(string $imageDir): array
    {
        $imageLoopsOrFiles = [];
        $includedVolumeGuids = $this->context->getIncludedVolumeGuids();
        $allVolumes = $this->context->getAllVolumes();

        foreach ($includedVolumeGuids as $guid) {
            $volume = $allVolumes[$guid];
            $volumeSize = $this->getImageFileSize($volume);
            $useGpt = $this->useGptPartitionScheme($volume);

            try {
                $imageLoopsOrFiles[$guid] = $this->prepareImageFile(
                    $imageDir,
                    $guid,
                    $volumeSize,
                    $volume['filesystem'],
                    $useGpt,
                    $volume['capacity'] ?? $volume['spaceTotal'] // shadowsnap does not have 'capacity' in this array, use 'spaceTotal' instead
                );
            } catch (Throwable $t) {
                $imageFilePaths = $this->filesystem->glob($imageDir . '/' . $guid . '.*');
                if (is_array($imageFilePaths)) {
                    foreach ($imageFilePaths as $imageFilePath) {
                        $this->removeBotchedImageFile($imageFilePath);
                    }
                }
                // After removing botched image files manually, re-throw and let other stages rollback
                throw $t;
            }
        }

        return $imageLoopsOrFiles;
    }

    /**
     * @param string $imageDir
     * @return string[] Filename array indexed by guid
     */
    private function prepareDiskImages(string $imageDir): array
    {
        $imageLoopsOrFiles = [];
        $includedGuids = $this->context->getIncludedDiskGuids();
        $allDisks = $this->context->getAllDisks();

        foreach ($includedGuids as $guid) {
            /** @var DiskDrive $disk */
            $disk = $allDisks[$guid];
            $diskSize = $disk->getCapacityInBytes();

            try {
                $imageLoopsOrFiles[$guid] = $this->prepareImageFile(
                    $imageDir,
                    $guid,
                    $diskSize,
                    '',
                    false,
                    $diskSize
                );
            } catch (Throwable $t) {
                $imageFilePaths = $this->filesystem->glob($imageDir . '/' . $guid . '.*');
                if (is_array($imageFilePaths)) {
                    foreach ($imageFilePaths as $imageFilePath) {
                        $this->removeBotchedImageFile($imageFilePath);
                    }
                }
                // After removing image files manually, re-throw and let other stages rollback
                throw $t;
            }
        }

        return $imageLoopsOrFiles;
    }

    /**
     * @return array
     */
    private function getAgentInfo()
    {
        return unserialize($this->context->getAgentConfig()->get('agentInfo'), ['allowed_classes' => false]);
    }

    /**
     * Prepare the image files.
     * Create the image file for the volume, if it does not exist.
     * Resize the image file, if the image file exists and the volume is larger than the image file size.
     *   (For Windows only)
     *
     * @param string $imageDir
     * @param string $guid
     * @param int $volumeSize
     * @param string $filesystem
     * @param bool $useGpt
     * @param int $capacity
     * @return string
     *  File path to the image file for unencrypted agents
     *  Or, name of loop device for encrypted agents
     */
    private function prepareImageFile(
        string $imageDir,
        string $guid,
        int $volumeSize,
        string $filesystem,
        bool $useGpt,
        int $capacity
    ): string {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $isEncrypted = $agent->getEncryption()->isEnabled();
        $imageExtension = $isEncrypted ? 'detto' : 'datto';

        $agentInfo = $this->getAgentInfo();
        $this->context->getLogger()->debug("BAK1001 Agent version", ['version' => $agentInfo['agentVersion']]);
        $encryptionKey = $isEncrypted ? $this->encryptionService->getAgentCryptKey($agent->getKeyName()) : null;

        $imageFile = $imageDir. '/' . $guid . '.' . $imageExtension;
        if ($this->filesystem->exists($imageFile)) {
            if ($agent->isForcePartitionRewrite()) {
                $this->context->getBackupImageFile()->createOrUpdatePartition(
                    $imageFile,
                    $filesystem,
                    $useGpt,
                    $isEncrypted,
                    $encryptionKey
                );
            }
            $wasResized = $this->context->getBackupImageFile()->resizeIfNeeded(
                $imageFile,
                $volumeSize,
                $filesystem,
                $useGpt,
                $isEncrypted,
                $encryptionKey
            );

            $usesCbtDriver = array_key_exists('driverType', $agentInfo) && $agentInfo['driverType'] === 'DattoCBT';
            if ($wasResized && !$usesCbtDriver) {
                $this->context->getLogger()->debug(
                    'BAK5013 Triggering diff-merge snapshot',
                    [
                        'agent' => $agent->getDisplayName(),
                        'volumeGuid' => $guid,
                        'imageExtension' => $imageExtension
                    ]
                );
                $this->diffMergeService->setDiffMergeAllVolumes($agent->getKeyName());
            }
            $this->context->clearAlert('BAK2040');
        }

        if ($this->context->hasChecksumFile()) {
            // Only copy an existing change id when the image file already exists. If it's being created we MUST take a full.
            $canMigrateChangeId = $this->filesystem->exists($imageFile);
            $this->prepareChecksumFile($imageDir, $guid, $capacity, $canMigrateChangeId);
        }

        // This is not an elseif as the resizeIfNeeded function above may remove the image file when resizing
        // an encrypted volume, so we want to recreate it here.
        if (!$this->filesystem->exists($imageFile)) {
            $this->context->getLogger()->info('BAK1002 Image file does not exist, creating.', ['imageFile' => $imageFile]);
            $this->context->getBackupImageFile()->create(
                $imageFile,
                $volumeSize,
                $filesystem,
                $guid,
                $useGpt,
                $isEncrypted,
                $encryptionKey
            );
            $this->context->clearAlert('BAK2040');
        }

        if ($isEncrypted) {
            $imageFileOrLoopDevice = $this->dmCryptManager->attach($imageFile, $encryptionKey);
        } else {
            $imageFileOrLoopDevice = $imageFile;
        }
        return $imageFileOrLoopDevice;
    }

    /**
     * Create the volume checksum file if it does not exist.
     *
     * @param string $imageDir
     * @param string $guid
     * @param int $capacity
     * @param bool $canMigrateChangeId
     */
    private function prepareChecksumFile(string $imageDir, string $guid, int $capacity, bool $canMigrateChangeId)
    {
        $checksumFile = $this->getChecksumFilename($imageDir, $guid);
        if (!$this->filesystem->exists($checksumFile)) {
            /** @var AgentlessSystem $agent */
            $agent = $this->context->getAsset();
            $platform = $agent->getPlatform();
            if ($platform->isAgentless()) {
                $this->createAgentlessChangeIdFile($checksumFile);
                if ($canMigrateChangeId) {
                    $this->copyChangeIdFromOldEsxInfo($agent, $guid, $checksumFile);
                }
            } else {
                $this->createChecksumFile($checksumFile, $capacity);
            }
        }
    }

    /**
     * Get the file path to the checksum file
     *
     * @param string $imageDir
     * @param string $guid
     * @return string File path to the checksum file
     */
    private function getChecksumFilename(string $imageDir, string $guid): string
    {
        return $imageDir . '/' . $guid . '.' . self::CHECKSUM_EXTENSION;
    }

    /**
     * On agentless the checksum file is used to store the changeId, one block of 512 bytes is enough to store it.
     *
     * @param string $checksumFile
     */
    private function createAgentlessChangeIdFile(string $checksumFile)
    {
        $this->sparseFileService->create(
            $checksumFile,
            self::CHANGE_ID_FILE_SIZE_BYTES,
            self::CHANGE_ID_SECTOR_SIZE_BYTES
        );
        $this->filesystem->filePutContents($checksumFile, self::FULL_BACKUP_ESX . PHP_EOL);
    }

    /**
     * @todo this is just so the first backup after upgrading is still an incremental.
     *
     * @param AgentlessSystem $agent
     * @param string $guid
     * @param string $checksumFile
     */
    private function copyChangeIdFromOldEsxInfo(AgentlessSystem $agent, string $guid, string $checksumFile)
    {
        $changeId = $this->getChangeIdOfPartition($agent, $guid);
        $this->context->getLogger()->debug('BAK1010 Copying change id to checksum file.', ['changeId' => $changeId]);

        $this->filesystem->filePutContents($checksumFile, $changeId . "\n");
    }

    /**
     * @todo this is just so the first backup after upgrading is still an incremental.
     *
     * @param AgentlessSystem $agent
     * @param string $guid
     * @return string
     */
    private function getChangeIdOfPartition(AgentlessSystem $agent, string $guid)
    {
        $partitionVmdkInfo = $this->getVmdkInfoOfPartition($agent->getEsxInfo()->getVmdkInfo(), $guid);

        return $partitionVmdkInfo['changeId'] ?? '';
    }

    /**
     * @todo this is just so the first backup after upgrading is still an incremental.
     *
     * @param array $vmdksInfo
     * @param string $guid
     * @return mixed|null
     */
    private function getVmdkInfoOfPartition(array $vmdksInfo, string $guid)
    {
        foreach ($vmdksInfo as $vmdkInfo) {
            $partitions = $vmdkInfo['partitions'];

            foreach ($partitions as $partition) {
                if ($partition['guid'] === $guid) {
                    return $vmdkInfo;
                }
            }
        }

        return null;
    }

    /**
     * Make a blank zeroed out file, it will get initialized on the agent side when it fails its magic number check.
     *
     * @param string $checksumFile
     * @param int $guidCapacity
     */
    private function createChecksumFile(string $checksumFile, int $guidCapacity)
    {
        $size = $guidCapacity + $this->context->getBackupImageFile()->getImageOverheadInBytes();

        $checksumSize = $this->getChecksumSize($size);
        $this->context->getLogger()->debug('BAK0014 Creating sparse checksum file.', ['size' => $checksumSize]);

        $this->sparseFileService->create(
            $checksumFile,
            $checksumSize,
            BackupImageFile::SECTOR_SIZE_IN_BYTES
        );
    }

    /**
     * Figure out how big the checksum file has to be for the given snapshot size.
     *
     * @param int $snapshotSize
     * @return int
     */
    private function getChecksumSize(int $snapshotSize): int
    {
        /* Based on the size of the snapshot, figure out how big the checksum file
         * size has to be. The logic here comes from metautils.c in rnd/diffmerge */
        $sizeofMetaHeader = 32;
        $sizeofMetaChecksum = 8;
        $fileChunkSize = 4 * 1024 * 1024; // libdiffmerge_common.h 4 megs
        $roundBy = BackupImageFile::SECTOR_SIZE_IN_BYTES;

        $sz = $sizeofMetaHeader +
            ((int)($snapshotSize / $fileChunkSize) * $sizeofMetaChecksum) +
            (($snapshotSize % $fileChunkSize) ? $sizeofMetaChecksum : 0);

        // round up to 512 to fill sector size
        return round(($sz + $roundBy / 2) / $roundBy) * $roundBy;
    }

    /**
     * Get the volume size for the image file
     * todo: move this determination logic into the agent classes
     *
     * @param array $volume
     * @return int
     */
    private function getImageFileSize(array $volume): int
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $platform = $agent->getPlatform();
        if ($platform === AgentPlatform::SHADOWSNAP() || $agent->isType(AssetType::AGENTLESS_WINDOWS)) {
            $volumeSize = $volume['spaceTotal'] + ByteUnit::MIB()->toByte(self::SHADOW_SNAP_RESERVED_MIB);
        } elseif ($platform === AgentPlatform::DATTO_WINDOWS_AGENT() ||
            $platform === AgentPlatform::DATTO_LINUX_AGENT() ||
            $platform === AgentPlatform::DIRECT_TO_CLOUD() ||
            $agent->isType(AssetType::AGENTLESS_LINUX)) {
            $volumeSize = $volume['spaceTotal'] + $this->context->getBackupImageFile()->getImageOverheadInBytes();
        } elseif ($platform === AgentPlatform::DATTO_MAC_AGENT()) {
            // todo: dirty hack, last block isn't aligned! inspect GPT
            $volumeSize = $volume['spaceTotal'] - BackupImageFile::SECTOR_SIZE_IN_BYTES;
        } elseif (!$agent->isSupportedOperatingSystem()) {
            $volumeSize = $volume['spaceTotal'];
        } else {
            $volumeSize = 0;
        }

        return $volumeSize;
    }

    /**
     * Determine whether to use GPT partition scheme based on agent type and volume information.
     * todo: move this determination logic into the agent classes
     *
     * @param array $volume
     * @return bool
     */
    private function useGptPartitionScheme(array $volume): bool
    {
        /** @var Agent $agent */
        $agent = $this->context->getAsset();
        $platform = $agent->getPlatform();

        if ($platform === AgentPlatform::SHADOWSNAP()) {
            $useGpt = $this->useGptForShadowSnap($volume);
        } elseif ($platform === AgentPlatform::DATTO_WINDOWS_AGENT()) {
            $useGpt = $this->useGptForDattoWindowsAgent($volume);
        } elseif ($platform === AgentPlatform::DATTO_LINUX_AGENT()) {
            $useGpt = $this->useGptForDattoLinuxAgent($volume);
        } elseif ($platform == AgentPlatform::DIRECT_TO_CLOUD()) {
            if ($agent->getOperatingSystem()->getOsFamily() == OsFamily::WINDOWS) {
                $useGpt = $this->useGptForDattoWindowsAgent($volume);
            } else {
                $useGpt = $this->useGptForDattoLinuxAgent($volume);
            }
        } elseif ($platform === AgentPlatform::DATTO_MAC_AGENT()) {
            $useGpt = $this->useGptForDattoMacAgent();
        } elseif ($agent->isType(AssetType::AGENTLESS_WINDOWS)) {
            $useGpt = $this->useGptForAgentlessWindows($volume);
        } elseif ($agent->isType(AssetType::AGENTLESS_LINUX)) {
            $useGpt = $this->useGptForDattoLinuxAgent($volume);
        } else {
            $useGpt = false;
        }

        return $useGpt;
    }

    /**
     * Determine whether to use GPT partition scheme for Agentless Windows and volume information.
     *
     * @param array $volume
     * @return bool
     */
    private function useGptForAgentlessWindows(array $volume): bool
    {
        // Agentless uses spaceTotal for GPT determination whereas Datto agents use capacity
        $isTooBigUsingSpaceTotal =
            isset($volume['spaceTotal']) &&
            (int)$volume['spaceTotal'] >= ByteUnit::TIB()->toByte(self::MBR_MAX_SIZE_TIB);

        $realPartSchemeSetToGpt =
            isset($volume['realPartScheme']) &&
            $volume['realPartScheme'] === PartitionService::GPT_VOL_TEXT;

        $useGpt = $isTooBigUsingSpaceTotal || $realPartSchemeSetToGpt;

        return $useGpt;
    }

    /**
     * Determine whether to use GPT partition scheme for ShadowSnap agent and volume information.
     * todo: move this determination logic into the agent classes
     *
     * @param array $volume
     * @return bool
     */
    private function useGptForShadowSnap(array $volume): bool
    {
        // todo: reevaluate the relationship between spaceTotal and capacity
        // ShadowSnap uses spaceTotal for GPT determination whereas Datto agents use capacity
        $isTooBigUsingSpaceTotal =
            isset($volume['spaceTotal']) &&
            (int)$volume['spaceTotal'] >= ByteUnit::TIB()->toByte(self::MBR_MAX_SIZE_TIB);

        $realPartSchemeSetToGpt =
            isset($volume['realPartScheme']) &&
            $volume['realPartScheme'] === PartitionService::GPT_VOL_TEXT;

        $volumeTypeIsBasicWithNoSerial =
            isset($volume['volumeType']) &&
            isset($volume['serialNumber']) &&
            $volume['volumeType'] == 'basic' &&
            $volume['serialNumber'] === '';

        $useGpt =
            $isTooBigUsingSpaceTotal ||
            $realPartSchemeSetToGpt ||
            $volumeTypeIsBasicWithNoSerial;
        return $useGpt;
    }

    /**
     * Determine whether to use GPT partition scheme for Windows agent and volume information.
     * todo: move this determination logic into the agent classes
     *
     * @param array $volume
     * @return bool
     */
    private function useGptForDattoWindowsAgent(array $volume): bool
    {
        $isTooBigUsingCapacity =
            isset($volume['capacity']) &&
            (int)$volume['capacity'] >= ByteUnit::TIB()->toByte(self::MBR_MAX_SIZE_TIB);

        $partSchemeSetToGpt =
            isset($volume['realPartScheme']) &&
            $volume['realPartScheme'] === PartitionService::GPT_VOL_TEXT;

        $useGpt =
            $isTooBigUsingCapacity ||
            $partSchemeSetToGpt;
        return $useGpt;
    }

    /**
     * Determine whether to use GPT partition scheme for Linux agent and volume information.
     * todo: move this determination logic into the agent classes
     *
     * @param array $volume
     * @return bool
     */
    private function useGptForDattoLinuxAgent(array $volume): bool
    {
        $isTooBigUsingCapacity =
            isset($volume['capacity']) &&
            (int)$volume['capacity'] >= ByteUnit::TIB()->toByte(self::MBR_MAX_SIZE_TIB);

        $isTooBigUsingSpaceTotal =
            isset($volume['spaceTotal']) &&
            (int)$volume['spaceTotal'] >= ByteUnit::TIB()->toByte(self::MBR_MAX_SIZE_TIB);

        $partSchemeSetToGpt =
            isset($volume['realPartScheme']) &&
            $volume['realPartScheme'] === PartitionService::GPT_VOL_TEXT;

        return $isTooBigUsingCapacity || $partSchemeSetToGpt || $isTooBigUsingSpaceTotal;
    }

    /**
     * Determine whether to use GPT partition scheme for Mac agent and volume information.
     * todo: move this determination logic into the agent classes
     *
     * @return bool
     */
    private function useGptForDattoMacAgent(): bool
    {
        return true;
    }
}
