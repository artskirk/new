<?php

namespace Datto\Asset;

use Datto\Asset\Agent\ArchiveService;
use Datto\Asset\RecoveryPoint\RecoveryPointInfoService;
use Datto\Config\AgentConfigFactory;
use Datto\Dataset\ZVolDataset;
use Datto\Iscsi\IscsiTarget;
use Datto\Restore\AssetCloneManager;
use Datto\Screenshot\ScreenshotFileRepository;
use Datto\Service\Verification\Local\FilesystemIntegrityCheckReportService;
use Datto\System\MountManager;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDataset;
use Datto\ZFS\ZfsDatasetService;
use Datto\ZFS\ZfsService;
use Datto\Log\DeviceLoggerInterface;
use Exception;

/**
 * Performs operations on orphaned ZFS datasets
 * (ZFS volumes which represent assets but have no corresponding key files).
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class OrphanDatasetService
{
    const BASE_CONFIG_PATH = '/datto/config/keys';
    const UUID_SOURCE_AGENTINFO = 'agentInfo';
    const UUID_SOURCE_NAME = 'volume name';
    const UUID_SOURCE_ZFS = 'ZFS property';

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var AssetService */
    private $assetService;

    /** @var ArchiveService */
    private $archiveService;

    /** @var UuidGenerator */
    private $uuidGenerator;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ZfsService */
    private $zfsService;

    /** @var IscsiTarget */
    private $iscsiTarget;

    /** @var MountManager */
    private $mountManager;

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var RecoveryPointInfoService */
    private $recoveryPointInfoService;

    /** @var TransfersService */
    private $transfersService;

    /**
     * @var FilesystemIntegrityCheckReportService
     */
    private $filesystemIntegrityCheckReportService;

    /**
     * @param ZfsDatasetService $zfsDatasetService
     * @param AgentConfigFactory $agentConfigFactory
     * @param Filesystem $filesystem
     * @param AssetService $assetService
     * @param ArchiveService $archiveService
     * @param UuidGenerator $uuidGenerator
     * @param DeviceLoggerInterface $logger
     * @param ZfsService $zfsService
     * @param IscsiTarget $iscsiTarget
     * @param MountManager $mountManager
     * @param AssetCloneManager $assetCloneManager
     * @param RecoveryPointInfoService $recoveryPointInfoService
     * @param TransfersService $transfersService
     * @param FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService
     */
    public function __construct(
        ZfsDatasetService $zfsDatasetService,
        AgentConfigFactory $agentConfigFactory,
        Filesystem $filesystem,
        AssetService $assetService,
        ArchiveService $archiveService,
        UuidGenerator $uuidGenerator,
        DeviceLoggerInterface $logger,
        ZfsService $zfsService,
        IscsiTarget $iscsiTarget,
        MountManager $mountManager,
        AssetCloneManager $assetCloneManager,
        RecoveryPointInfoService $recoveryPointInfoService,
        TransfersService $transfersService,
        FilesystemIntegrityCheckReportService $filesystemIntegrityCheckReportService
    ) {
        $this->zfsDatasetService = $zfsDatasetService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->filesystem = $filesystem;
        $this->assetService = $assetService;
        $this->archiveService = $archiveService;
        $this->uuidGenerator = $uuidGenerator;
        $this->logger = $logger;
        $this->zfsService = $zfsService;
        $this->iscsiTarget = $iscsiTarget;
        $this->mountManager = $mountManager;
        $this->assetCloneManager = $assetCloneManager;
        $this->recoveryPointInfoService = $recoveryPointInfoService;
        $this->transfersService = $transfersService;
        $this->filesystemIntegrityCheckReportService = $filesystemIntegrityCheckReportService;
    }

    /**
     * Assigns a ZFS UUID property to all orphan datasets whereever possible.
     */
    public function createAllMissingUuids(): void
    {
        $orphanDatasets = $this->findOrphanDatasets();
        foreach ($orphanDatasets as $dataset) {
            try {
                $this->createMissingUuid($dataset);
            } catch (Exception $e) {
                $datasetName = $dataset->getName();
                $this->logger->error(
                    'ODS0001 Can\'t set UUID for dataset',
                    [
                        'dataset' => $datasetName,
                        'exception' => $e
                    ]
                );
            }
        }
    }

    /**
     * Assigns a ZFS UUID property to an orphan ZFS dataset if none exists.
     * This first tries to determine the existing asset UUID from the dataset
     * contents, and, if that fails, it generates a new UUID.
     *
     * @param ZfsDataset $dataset
     */
    private function createMissingUuid(ZfsDataset $dataset)
    {
        if ($dataset->getUuid()) {
            return;
        }

        $datasetName = $dataset->getName();
        $this->logger->info('ODS0002 Orphan dataset does not have a UUID.', ['dataset' => $datasetName]);

        $uuid = $this->getUuidFromDataset($dataset);

        if ($uuid && $this->isUuidInUse($uuid)) {
            throw new Exception("Can't assign UUID \"$uuid\" to dataset \"$datasetName\" because it's already in use by another asset.");
        }

        if (!$uuid) {
            $uuid = $this->uuidGenerator->get();
            $this->logger->info('ODS0004 Created new UUID for dataset', ['dataset' => $datasetName, 'uuid' => $uuid]);
        }

        $dataset->setUuid($uuid);
        $this->logger->info('ODS0005 Assigned UUID to dataset', ['dataset' => $datasetName, 'uuid' => $uuid]);
    }

    /**
     * Searches for orphan ZFS datasets and returns a list of any it found.
     *
     * @return ZfsDataset[]
     */
    public function findOrphanDatasets(): array
    {
        $orphanDatasets = [];
        $datasets = $this->zfsDatasetService->getAllDatasets();
        foreach ($datasets as $dataset) {
            if ($this->isAgentDataset($dataset) || $this->isShareDataset($dataset)) {
                $assetKeyName = $this->getAssetKeyName($dataset);
                if (!$this->assetService->exists($assetKeyName)) {
                    $agentConfig = $this->agentConfigFactory->create($assetKeyName);
                    if (!$agentConfig->has('agentInfo')) {
                        $orphanDatasets[] = $dataset;
                    }
                }
            }
        }
        return $orphanDatasets;
    }

    /**
     * Gets the hostname from the ".agentInfo" file in the dataset, if it exists.
     *
     * @param ZfsDataset $dataset
     * @return string hostname or "" if no hostname is set
     */
    public function getHostnameFromAgentInfo(ZfsDataset $dataset): string
    {
        return $this->getValueFromAgentInfo('hostname', $dataset);
    }

    /**
     * Check if a dataset is recoverable.
     *
     * @param ZfsDataset $dataset
     * @return bool
     */
    public function isDatasetRecoverable(ZfsDataset $dataset)
    {
        try {
            $this->checkDatasetRecoverable($dataset);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tests if the given ZFS dataset can be recovered, and throws an exception
     * if it cannot be.
     *
     * @param ZfsDataset $dataset
     */
    public function checkDatasetRecoverable(ZfsDataset $dataset): void
    {
        $configKeyBase = self::BASE_CONFIG_PATH;
        $assetKeyName = $this->getAssetKeyName($dataset);
        $mountPoint = $dataset->getMountPoint();

        /*
         * Shares are currently not recoverable because they don't contain
         * key files.
         */

        if ($this->isShareDataset($dataset)) {
            throw new Exception('Share datasets cannot be recovered');
        }

        /*
         * Make sure the dataaset has a mount point and is mounted.
         * If it's not mounted, we can't recover the key files.
         */
        if (!$mountPoint || $mountPoint === '-') {
            throw new Exception('Dataset has no mountpoint');
        }

        if (!$dataset->isMounted()) {
            throw new Exception('Dataset is not mounted');
        }

        /*
         * Check that required key files are in the dataset and that they
         * are not already in the device.  The recover function will not
         * overwrite existing key files which could destroy an existing asset.
         */
        $requiredKeyNames = [ 'agentInfo' ];
        foreach ($requiredKeyNames as $keyName) {
            if (!$this->filesystem->exists("$mountPoint/config/$assetKeyName.$keyName")) {
                throw new Exception("Required key file \"$assetKeyName.$keyName\" is not in dataset");
            }
            if ($this->filesystem->exists("$configKeyBase/$assetKeyName.$keyName")) {
                throw new Exception("Key file \"$assetKeyName.$keyName\" conflicts with existing asset");
            }
        }

        /*
         * Check that key files in dataset will not overwrite any existing files.
         */
        $files = $this->filesystem->glob("$mountPoint/config/*");
        foreach ($files as $datasetFilePath) {
            $fileName = basename($datasetFilePath);
            $systemFilePath = "$configKeyBase/$fileName";
            if ($this->filesystem->exists($systemFilePath)) {
                throw new Exception("Dataset key file \"$fileName\" already exists on system -- overwrite disallowed");
            }
        }

        /*
         * Even if there is no key file conflict, do an extra check to make
         * sure there aren't some existing key files that could cause problems.
         */
        $files = $this->filesystem->glob("$configKeyBase/$assetKeyName.*");
        $files = preg_grep('/\.(log|lastSentAssetInfo$)/', $files, PREG_GREP_INVERT);
        if ($files) {
            $filename = preg_replace('#^.*/#', '', $files[0]);
            throw new Exception("Existing asset key file \"$filename\" could cause issues");
        }

        /*
         * Additional checks we could perform:
         * 1. For angentless systems, make sure the hypervisor is defined.
         */
    }

    /**
     * Recovers an orphan ZFS dataset and places the asset in the archived state.
     *
     * @param ZfsDataset $dataset
     */
    public function recoverDataset(ZfsDataset $dataset): void
    {
        $this->checkDatasetRecoverable($dataset);

        $configKeyBase = self::BASE_CONFIG_PATH;
        $assetKeyName = $this->getAssetKeyName($dataset);
        $mountPoint = $dataset->getMountPoint();

        $error = false;
        $copiedFiles = [];
        foreach ($this->filesystem->glob("$mountPoint/config/$assetKeyName.*") as $filePath) {
            $fileName = basename($filePath);
            if ($this->filesystem->copy($filePath, $configKeyBase)) {
                $copiedFiles[] = "$configKeyBase/$fileName";
            } else {
                $error = true;
                break;
            }
        }
        if ($error) {
            /* Undo a partial copy */
            foreach ($copiedFiles as $filePath) {
                $this->filesystem->unlink($filePath);
            }
            throw new Exception("Error copying key files");
        }

        $this->archiveAsset($assetKeyName);
        $this->refreshAsset($assetKeyName);
    }

    /**
     * Gets the asset key name for a ZFS dataset.
     *
     * @param ZfsDataset $dataset
     * @return string
     */
    public function getAssetKeyName(ZfsDataset $dataset): string
    {
        $fullDatasetName = $dataset->getName();
        return preg_replace('#^.*/#', '', $fullDatasetName);
    }

    /**
     * @param string $dataset
     * @throws Exception
     */
    public function destroy(string $dataset): void
    {
        $orphans = $this->findOrphanDatasets();

        foreach ($orphans as $orphan) {
            if ($dataset === $orphan->getName()) {
                $this->removeTargetIfNeeded($dataset);
                $this->unmountIfNeeded($dataset);
                $this->assetCloneManager->destroyLoops($orphan->getMountPoint());

                $canDestroy = $this->zfsService->destroyDryRun($dataset, true);
                if ($canDestroy) {
                    $this->deleteOrphanFilesystemIntegrityCheckReports($dataset);
                    $this->deleteOrphanScreenshots($dataset);
                    $this->deleteOrphanKeys($dataset);
                    $this->zfsService->destroyDataset($dataset, true);
                } else {
                    throw new Exception("Cannot destroy dataset");
                }

                return;
            }
        }

        throw new Exception("Dataset is not an orphan");
    }

    /**
     * Determines if a ZFS dataset represents a share asset.
     *
     * @param ZfsDataset $dataset
     * @return bool
     */
    public function isShareDataset(ZfsDataset $dataset): bool
    {
        $fullDatasetName = $dataset->getName();
        return preg_match('#^homePool/home/(?!agents$|configBackup$|owncloud$)[^/]+$#', $fullDatasetName);
    }

    /**
     * Determines if a ZFS dataset represents an agent asset.
     *
     * @param ZfsDataset $dataset
     * @return bool
     */
    public function isAgentDataset(ZfsDataset $dataset): bool
    {
        $fullDatasetName = $dataset->getName();
        return preg_match('#^homePool/home/agents/[^/]+$#', $fullDatasetName);
    }

    /**
     * Delete any leftover keys
     *
     * @param string $dataset
     */
    private function deleteOrphanKeys(string $dataset)
    {
        $assetKey = basename($dataset);
        $keyBase = self::BASE_CONFIG_PATH . "/" . $assetKey;
        if ($this->filesystem->exists("$keyBase.agentInfo")) {
            return;
        }

        $keys = $this->filesystem->glob("$keyBase.*");

        foreach ($keys as $key) {
            $this->filesystem->unlink($key);
        }
    }

    /**
     * Delete any leftover screenshot verification files
     *
     * @param string $dataset
     */
    private function deleteOrphanScreenshots(string $dataset): void
    {
        $assetKey = basename($dataset);
        $keyBaseFormatted = sprintf(ScreenshotFileRepository::SCREENSHOT_PATH_FORMAT, $assetKey, '*');
        // Remove screenshots
        $screenshotFiles = $this->filesystem->glob($keyBaseFormatted);
        foreach ($screenshotFiles as $screenshotFile) {
            $this->filesystem->unlink($screenshotFile);
        }
    }

    /**
     * Delete any leftover filesystem integrity check reports
     *
     * @param string $dataset
     */
    private function deleteOrphanFilesystemIntegrityCheckReports(string $dataset)
    {
        $assetKey = basename($dataset);
        $keyBase = self::BASE_CONFIG_PATH . "/" . $assetKey;
        if ($this->filesystem->exists("$keyBase.agentInfo")) {
            return;
        }

        $this->filesystemIntegrityCheckReportService->destroyAssetReports($assetKey);
    }

    /**
     * Attempts to retrieve a UUID from an orphan dataset by searching in the
     * following places (in order):
     * 1. The ".agentInfo" file
     * 2. The ZFS "datto:uuid" property
     * 3. The ZFS volume name (if it's a UUID)
     *
     * @param ZfsDataset $dataset The orphan dataset
     * @return string The UUID, if found, or "" if no UUID could be found.
     */
    private function getUuidFromDataset(ZfsDataset $dataset): string
    {
        $uuid = $this->getValueFromAgentInfo('uuid', $dataset);
        if ($uuid) {
            $source = self::UUID_SOURCE_AGENTINFO;
        } else {
            $uuid = $dataset->getUuid();
            if ($uuid) {
                $source = self::UUID_SOURCE_ZFS;
            } else {
                $assetKeyName = $this->getAssetKeyName($dataset);
                if (preg_match('/^[0-9a-f]{32}$/', $assetKeyName)) {
                    $uuid = $assetKeyName;
                    $source = self::UUID_SOURCE_NAME;
                } else {
                    $uuid = '';
                    $source = '';
                }
            }
        }

        if ($uuid) {
            $datasetName = $dataset->getName();
            $this->logger->info('ODS0003 Found UUID in source from dataset', ['source' => $source, 'dataset' => $datasetName]);
        }

        return $uuid ?: '';
    }

    /**
     * Gets the value for the key from the ".agentInfo" file in the dataset, if it exists.
     *
     * @param string $key
     * @param ZfsDataset $dataset
     * @return string value for key or "" if key is not set
     */
    private function getValueFromAgentInfo(string $key, ZfsDataset $dataset): string
    {
        $value = '';
        $mountPoint = $dataset->getMountPoint();
        if ($mountPoint && $mountPoint !== '-' && $this->isAgentDataset($dataset)) {
            $assetKeyName = $this->getAssetKeyName($dataset);
            $agentInfoFilePath = "$mountPoint/config/$assetKeyName.agentInfo";
            if ($this->filesystem->exists($agentInfoFilePath)) {
                $agentInfoFileContents = $this->filesystem->fileGetContents($agentInfoFilePath);
                if ($agentInfoFileContents === false) {
                    throw new Exception("Error reading file \"$agentInfoFilePath\"");
                }
                $agentInfo = unserialize($agentInfoFileContents, ['allowed_classes' => false]);
                if ($agentInfo === false) {
                    throw new Exception("Error unserializing file \"$agentInfoFilePath\"");
                }
                if (isset($agentInfo[$key])) {
                    $value = $agentInfo[$key];
                }
            }
        }
        return $value;
    }

    /**
     * Determines if the given UUID is already in use by an existing asset.
     *
     * @param string $uuid
     * @return bool
     */
    private function isUuidInUse(string $uuid): bool
    {
        $assets = $this->assetService->getAll();
        foreach ($assets as $asset) {
            if ($asset->getUuid() == $uuid) {
                return true;
            }
        }
        return false;
    }

    /**
     * Archives an asset.
     * If the asset type does not support archiving, then the asset is paused.
     *
     * @param string $assetKeyName
     */
    private function archiveAsset(string $assetKeyName): void
    {
        $agentConfig = $this->agentConfigFactory->create($assetKeyName);
        if ($agentConfig->isShare()) {
            $this->pauseAsset($assetKeyName);
        } else {
            $this->archiveService->archive($assetKeyName);
        }
    }

    /**
     * Pauses an asset.
     *
     * @param string $assetKeyName
     */
    private function pauseAsset(string $assetKeyName): void
    {
        $asset = $this->assetService->get($assetKeyName);
        if (!$asset->getLocal()->isPaused()) {
            $asset->getLocal()->setPaused(true);
            $this->assetService->save($asset);
        }
    }

    /**
     * @param string $datasetName
     */
    private function unmountIfNeeded(string $datasetName)
    {
        $mounts = $this->mountManager->getMounts();
        $zvolDeviceLink = basename($this->getZvolLink($datasetName) ?? '');
        if (empty($zvolDeviceLink)) {
            return;
        }
        foreach ($mounts as $mount) {
            $device = $mount->getDevice();
            $zvolRegex = sprintf("|/dev/%sp|", $zvolDeviceLink);
            if (preg_match($zvolRegex, $device)) {
                $this->mountManager->unmount($mount->getMountPoint());
            }
        }
    }

    /**
     * @param string $datasetName
     */
    private function removeTargetIfNeeded(string $datasetName): void
    {
        $zvolPath = $this->getDatasetZvolPath($datasetName);
        try {
            $targets = $this->iscsiTarget->getTargetsByPath($zvolPath);
        } catch (\Throwable $e) {
            // This is not an error.  An exception will occur normally for
            // non-iSCSI assets or assets that no-longer have a backing store.
            $targets = [];
        }
        foreach ($targets as $targetName) {
            try {
                $this->iscsiTarget->closeSessionsOnTarget($targetName);
                $this->iscsiTarget->deleteTarget($targetName);
                $this->iscsiTarget->writeChanges();
            } catch (\Throwable $e) {
                $this->logger->warning('ODS0006 Failed to delete iSCSI target', ['target' => $targetName, 'exception' => $e]);
            }
        }
    }

    /**
     * @param string $datasetName
     * @return string|null
     */
    private function getZvolLink(string $datasetName)
    {
        $zvolPath = $this->getDatasetZvolPath($datasetName);

        if ($this->filesystem->exists($zvolPath)) {
            return $this->filesystem->readlink($zvolPath);
        } else {
            return null;
        }
    }

    /**
     * @param string $datasetName
     * @return string
     */
    private function getDatasetZvolPath(string $datasetName): string
    {
        return ZVolDataset::BLK_BASE_DIR . "/" . $datasetName;
    }

    /**
     * Refresh any files that contain the state of the asset.
     *
     * @param string $assetKeyName
     */
    private function refreshAsset(string $assetKeyName): void
    {
        $this->logger->info('ODS0007 Refreshing key files for asset', ['asset' => $assetKeyName]);

        $asset = $this->assetService->get($assetKeyName);

        $this->logger->debug('ODS0008 Refreshing recovery points key files ...');
        $this->recoveryPointInfoService->refreshKeys($asset);
        $this->recoveryPointInfoService->refreshCaches($asset);

        $this->logger->debug('ODS0009 Refreshing transfers key file ...');
        $this->transfersService->generateMissing($asset->getKeyName());
    }
}
