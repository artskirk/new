<?php

namespace Datto\Restore;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Asset\Agent\DmCryptManager;
use Datto\Asset\Agent\EncryptionService;
use Datto\Asset\Agent\Volume;
use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\AssetType;
use Datto\Block\LoopInfo;
use Datto\Block\LoopManager;
use Datto\Core\Storage\StorageInfo;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Utility\Process\ProcessCleanup;
use Datto\Filesystem\SysFs;
use Datto\Common\Utility\Filesystem;
use Datto\Core\Storage\StorageInterface;
use Datto\Restore\Exception\AssetCloneValidationException;
use Datto\Utility\Block\Blockdev;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Handles managing zfs clones
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class AssetCloneManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const EXTENSION_DETTO = '.detto';
    const EXTENSION_DATTO = '.datto';

    private Filesystem $filesystem;
    private SysFs $sysFs;
    private LoopManager $loopManager;
    private DmCryptManager $dmCryptManager;
    private EncryptionService $encryptionService;
    private AssetService $assetService;
    private ProcessCleanup $processCleanup;
    private Blockdev $blockdev;
    private StorageInterface $storage;
    private AgentSnapshotRepository $agentSnapshotRepository;
    private Collector $collector;

    public function __construct(
        Filesystem $filesystem,
        SysFs $sysFs,
        LoopManager $loopManager,
        DmCryptManager $dmCryptManager,
        AssetService $assetService,
        EncryptionService $encryptionService,
        ProcessCleanup $processCleanup,
        Blockdev $blockdev,
        StorageInterface $storage,
        AgentSnapshotRepository $agentSnapshotRepository,
        Collector $collector
    ) {
        $this->filesystem = $filesystem;
        $this->sysFs = $sysFs;
        $this->loopManager = $loopManager;
        $this->dmCryptManager = $dmCryptManager;
        $this->encryptionService = $encryptionService;
        $this->assetService = $assetService;
        $this->processCleanup = $processCleanup;
        $this->blockdev = $blockdev;
        $this->storage = $storage;
        $this->agentSnapshotRepository = $agentSnapshotRepository;
        $this->collector = $collector;
    }

    /**
     * Check if a clone already exists.
     *
     * @param CloneSpec $cloneSpec
     * @return bool
     */
    public function exists(CloneSpec $cloneSpec): bool
    {
        return $this->storage->storageExists($cloneSpec->getTargetDatasetName());
    }

    /**
     * Clone a zfs snapshot for the given asset. If the agent is encrypted, loopback devices and dev mappers
     * can be set up to make the .detto file transparent.
     *
     * @param CloneSpec $cloneSpec
     * @param bool $ensureDecrypted True to set up block devices to make the .detto file transparent
     */
    public function createClone(CloneSpec $cloneSpec, bool $ensureDecrypted = true): void
    {
        $this->logger->setAssetContext($cloneSpec->getAssetKey());

        // TODO: why lookup the asset again when clients may already have it?
        $asset = $this->assetService->get($cloneSpec->getAssetKey());

        try {
            if ($this->exists($cloneSpec)) {
                // do not exit early, allow ensureCloneDecrypted to run which will repair a broken dmcrypt symlink
                $this->logger->debug('CLM0001 dataset already exists', [
                    'dataset' => $cloneSpec->getTargetDatasetName()
                ]);
            } else {
                $cloneContext = $cloneSpec->createCloneCreationContext();
                $cloneSource = $cloneSpec->getCloneSource();

                $this->storage->cloneSnapshot($cloneSource, $cloneContext);

                $this->logger->debug('CLM0003 dataset was cloned successfully', [
                    'dataset' => $cloneSpec->getTargetDatasetName()
                ]);
            }

            $this->validateCloneMountpointData($cloneSpec, $asset);

            if ($ensureDecrypted && $asset->isType(AssetType::AGENT)) {
                /** @var Agent $asset */
                $this->ensureAgentCloneDecrypted($asset, $cloneSpec);
            }
        } catch (AssetCloneValidationException $e) {
            $this->logger->error(
                'CLM0005 Possibly cross-mounted dataset clone detected',
                [
                    'dataset' => $cloneSpec->getTargetDatasetName(),
                    'errorMessage' => $e->getMessage()
                ]
            );
            $this->collector->increment(Metrics::ZFS_CLONE_BAD_MOUNT);

            throw $e;
        } catch (Exception $e) {
            // ZfsStorage::cloneSnapshot also logs, so this is partly redundant,
            // but leave these logs for better visibility, since it covers
            // other falure modes as well.
            $this->logger->error(
                'CLM0002 Failed to clone',
                [
                    'sourceCloneName' => $cloneSpec->getSourceCloneName(),
                    'targetDatasetName' => $cloneSpec->getTargetDatasetName(),
                    'exception' => $e
                ]
            );
            throw $e;
        }
    }

    /**
     * If the agent clone directory contains encrypted detto files, they will be transparently decrypted.
     * Requires that the agent has previously been decrypted.
     *
     * @param Agent $agent
     * @param CloneSpec $cloneSpec
     */
    public function ensureAgentCloneDecrypted(Agent $agent, CloneSpec $cloneSpec): void
    {
        $targetDir = $cloneSpec->getTargetMountpoint();

        if (!$this->filesystem->exists($cloneSpec->getTargetMountpoint())) {
            throw new \RuntimeException("Agent clone directory '$targetDir' does not exist");
        }

        if ($agent->getEncryption()->isEnabled()) {
            // In case of Encrypted Rescue Agent snapshot restore, it is ok to delete existing .datto files as they
            // are read-only. Please refer to BCDR-14636.
            $isRescueAgentSnapshot = $agent->isRescueAgent() && $cloneSpec->getSnapshotName();
            $encryptionKey = $this->encryptionService->getAgentCryptKey($agent->getKeyName());
            $clonedDettos = $this->filesystem->glob($targetDir . '/*' . static::EXTENSION_DETTO);
            foreach ($clonedDettos as $detto) {
                $datto = str_replace(static::EXTENSION_DETTO, static::EXTENSION_DATTO, $detto);
                $this->dmCryptManager->makeTransparent($datto, $encryptionKey, $isRescueAgentSnapshot);
            }
        }
    }

    /**
     * Clean up all zfs clones for an asset which have a specific clone suffix.
     *
     * @param string $assetKeyName
     * @param string $cloneSuffix
     */
    public function cleanOrphanedClones(string $assetKeyName, string $cloneSuffix): void
    {
        $this->logger->setAssetContext($assetKeyName);

        foreach ($this->getAllClones() as $cloneSpec) {
            if ($cloneSpec->getAssetKey() === $assetKeyName && $cloneSpec->getSuffix() === $cloneSuffix) {
                try {
                    $this->destroyClone($cloneSpec);
                    $this->logger->info('CLM0031 Destroyed orphaned clone dataset', [
                        'dataset' => $cloneSpec->getTargetDatasetName()
                    ]);
                } catch (Throwable $throwable) {
                    $this->logger->error(
                        'CLM0032 Error destroying orphaned clone dataset',
                        [
                            'dataset' => $cloneSpec->getTargetDatasetName(),
                            'exception' => $throwable
                        ]
                    );
                }
            }
        }
    }

    /**
     * Destroy a single, specific, zfs clone
     *
     * @param CloneSpec $cloneSpec
     * @param bool $recursive
     * @throws Exception
     */
    public function destroyClone(CloneSpec $cloneSpec, bool $recursive = false): void
    {
        $this->logger->setAssetContext($cloneSpec->getAssetKey());
        $this->logger->debug('CLM0055 Destroying dataset', [
            'suffix' => $cloneSpec->getSuffix(),
            'dataset' => $cloneSpec->getTargetDatasetName()
        ]);

        if (!$this->exists($cloneSpec)) {
            $this->logger->warning('CLM0052 dataset does not exist', [
                'dataset' => $cloneSpec->getTargetDatasetName()
            ]);
            return;
        }

        $storageId = $cloneSpec->getTargetDatasetName();
        $storageInfo = $this->storage->getStorageInfo($storageId);

        if ($storageInfo->getType() === StorageType::STORAGE_TYPE_FILE) {
            $this->processCleanup->killProcessesUsingDirectory($storageInfo->getFilePath(), $this->logger);
            $this->destroyLoops($storageInfo->getFilePath());
        }

        try {
            $this->storage->destroyStorage($cloneSpec->getTargetDatasetName(), $recursive);
            $this->logger->debug('CLM0023 Successfully destroyed dataset', [
                'dataset' => $cloneSpec->getTargetDatasetName()
            ]);
        } catch (Throwable $e) {
            $message = "CLM0024 Failed to destroy dataset: {$cloneSpec->getTargetDatasetName()}";
            throw new Exception($message, $e->getCode(), $e);
        }
    }

    /**
     * Flush the buffers of a ZFS volume given the ZFS path to the volume.
     * The dataset must be of type "volume" and not "filesystem".
     *
     * @param string $zfsPath e.g. homePool/414561854a05480ba9602e2f5702253b-1616427895-file
     */
    public function flushBuffers(string $zfsPath): void
    {
        try {
            $blockLink = "/dev/zvol/$zfsPath";
            $blockDevice = $this->filesystem->realpath($blockLink);
            if ($blockDevice) {
                $this->blockdev->flushBuffers($blockDevice);
            } else {
                $this->logger->warning('CLM0100 Can\'t find block device for dataset', ['zfsPath' => $zfsPath]);
            }
        } catch (Exception $e) {
            $this->logger->warning('CLM0101 Error while flushing buffers', ['exception' => $e]);
        }
    }

    /**
     * Destroy the loops associated with the given mount point.
     *
     * @param string $mountpoint
     * TODO: this function doesn't feel like it belongs here
     */
    public function destroyLoops(string $mountpoint): void
    {
        $this->logger->debug('CLM0025 removing any loops and DM devices point at mountpoint', ['mountpoint' => $mountpoint]);

        if ($this->filesystem->isDir($mountpoint) === false) {
            $mountpoint = dirname($mountpoint);
        }

        $dmDevices = $this->sysFs->getDmDevices();
        $this->dmCryptManager->setLogger($this->logger);

        foreach ($dmDevices as $dmDevice) {
            $slaveLoops = $this->sysFs->getSlaves($dmDevice['path']);
            $backingFiles = array_map(function (LoopInfo $loopInfo) {
                return $loopInfo->getBackingFilePath();
            }, $slaveLoops);

            if ($backingFiles && $mountpoint === dirname($backingFiles[0])) {
                try {
                    $this->dmCryptManager->detach($dmDevice['path']);
                } catch (Throwable $ex) {
                    continue; // exception is logged in LoopManager
                }
            }
        }

        $loops = $this->sysFs->getLoops();
        foreach ($loops as $loopInfo) {
            if ($mountpoint === dirname($loopInfo->getBackingFilePath())) {
                try {
                    $this->loopManager->destroy($loopInfo);
                } catch (Throwable $ex) {
                    continue; // exception is logged in LoopManager
                }
            }
        }
    }

    /**
     * @return CloneSpec[]
     */
    public function getAllClones(): array
    {
        $storageInfos = $this->storage->getStorageInfos('', true);
        $cloneSpecs = [];
        foreach ($storageInfos as $storageInfo) {
            $cloneSpec = CloneSpec::fromZfsDatasetAttributes(
                $storageInfo->getId(),
                $storageInfo->getParent() ?: StorageInfo::STORAGE_PROPERTY_NOT_APPLICABLE,
                $storageInfo->hasValidMountpoint() ?
                    $storageInfo->getFilePath() :
                    StorageInfo::STORAGE_LOCATION_UNKNOWN
            );

            if (!empty($cloneSpec)) {
                $cloneSpecs[] = $cloneSpec;
            }
        }

        return $cloneSpecs;
    }

    private function validateCloneMountpointData(CloneSpec $cloneSpec, Asset $asset): void
    {
        $mountpoint = $cloneSpec->getTargetMountpoint();
        $assetKey = $cloneSpec->getAssetKey();
        $snapshotName = $cloneSpec->getSnapshotName();

        // shares won't have mountpoints, and cannot be validated.
        if (empty($mountpoint)) {
            return;
        }

        $mountedAgentInfo = "{$mountpoint}/{$assetKey}.agentInfo";
        $desiredAgentInfo = sprintf(
            '%s/%s',
            CloneSpec::AGENT_ZFS_MOUNT_ROOT,
            "{$assetKey}/.zfs/snapshot/{$snapshotName}/{$assetKey}.agentInfo"
        );

        // find all agent info files in the mount dir and extract file names only
        $agentInfoFileNames = array_map(
            fn(string $agentInfoPath) => $this->filesystem->filename($agentInfoPath),
            $this->filesystem->glob("$mountpoint/*.agentInfo") ?: []
        );

        if (!$agentInfoFileNames) {
            throw new AssetCloneValidationException(
                'There are no .agentInfo files in the cloned mounpoint'
            );
        }

        // check if the agentInfo is really coming from the requested snapshot
        $mountedSum = $this->filesystem->sha1($mountedAgentInfo);
        $desiredSum = $this->filesystem->sha1($desiredAgentInfo);
        if ($mountedSum !== $desiredSum) {
            throw new AssetCloneValidationException(
                'The cloned dataset mountpoint has unexpected agentInfo file content'
            );
        }

        // presence of multiple .agentInfo files needs a deeper dive
        if (count($agentInfoFileNames) > 1) {
            if (!$asset instanceof Agent) {
                throw new AssetCloneValidationException(
                    'Multiple agentInfo files detected for non-agent asset'
                );
            }

            $this->checkAllAgentInfosMatchAgent(
                $asset,
                $agentInfoFileNames,
                $snapshotName
            );
        }
    }

    /**
     * Checks whether extra agentInfo files are from requested agent.
     *
     * When cloned data validation runs there may be more than one agentInfo
     * files present in the clone. Those files may be left over after agent
     * type conversions or rescue agent files. Such files need to be looked
     * into, to heuristically confirm that they belong to the requested agent,
     * not a completely different assset. This is done by comparing stored
     * volume information.
     *
     * @param Agent $agent
     * @param string[] $agentInfoFileNames
     * @param string $snapshotEpoch
     */
    private function checkAllAgentInfosMatchAgent(
        Agent $agent,
        array $agentInfoFileNames,
        string $snapshotEpoch
    ): void {
        $agentVolumes = $agent->getVolumes();

        $osVolume = null;
        /** @var Volume $volume */
        foreach ($agentVolumes as $volume) {
            if ($volume->isOsVolume()) {
                $osVolume = $volume;
            }
        }

        if (!$osVolume) {
            // only agents are guaranteed to have OS Volume
            if ($agent->isType(AssetType::AGENT)) {
                throw new AssetCloneValidationException(
                    'Could not find OS volume for agent'
                );
            }

            return;
        }

        foreach ($agentInfoFileNames as $agentKey) {
            $volumes = $this->agentSnapshotRepository->getVolumes(
                $agentKey,
                (int) $snapshotEpoch
            );
            /** @var Volume $volume */
            foreach ($volumes as $volume) {
                if ($volume->isOsVolume() &&
                    $volume->getGuid() !== $osVolume->getGuid()
                ) {
                    throw new AssetCloneValidationException(
                        'Mismatching agentInfo file present in the cloned mountpoint'
                    );
                }
            }
        }
    }
}
