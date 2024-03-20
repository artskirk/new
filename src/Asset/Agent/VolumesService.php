<?php

namespace Datto\Asset\Agent;

use Datto\AppKernel;
use Datto\Asset\Agent\Backup\AgentSnapshotRepository;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\ByteUnit;
use Datto\Common\Utility\Filesystem;
use Exception;
use Psr\Log\LoggerAwareInterface;

class VolumesService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SWAP_MOUNTPOINT = '<swap>';
    const DELETING_DATASET_KEY_PATTERN = 'deletingVolumeDataset.%s';
    const DELETE_AFTER_ROLLBACK_KEY_PATTERN = 'deleteAfterRollback.%s';

    private AgentConfigFactory $agentConfigFactory;
    private Filesystem $filesystem;
    private IncludedVolumesKeyService $includedVolumesKeyService;
    private VolumesCollector $volumesCollector;
    private VolumesNormalizer $volumeNormalizer;
    private AgentSnapshotRepository $agentSnapshotRepository;

    public function __construct(
        AgentConfigFactory $agentConfigFactory = null,
        Filesystem $filesystem = null,
        IncludedVolumesKeyService $includedVolumesKeyService = null,
        VolumesCollector $volumesCollector = null,
        VolumesNormalizer $volumeNormalizer = null,
        AgentSnapshotRepository $agentSnapshotRepository = null
    ) {
        $this->agentConfigFactory = $agentConfigFactory ?: new AgentConfigFactory();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->volumeNormalizer = $volumeNormalizer ?: new VolumesNormalizer();
        $this->volumesCollector = $volumesCollector ?: new VolumesCollector($this->volumeNormalizer);
        $this->includedVolumesKeyService = $includedVolumesKeyService ?:
            AppKernel::getBootedInstance()->getContainer()->get(IncludedVolumesKeyService::class);
        $this->agentSnapshotRepository = $agentSnapshotRepository ?:
            AppKernel::getBootedInstance()->getContainer()->get(AgentSnapshotRepository::class);
    }

    public function getVolumesFromKeyNoIncludes(string $agentKey): Volumes
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentInfo = $agentConfig->getRaw('agentInfo', '');
        return $this->getVolumesFromKeyContents($agentKey, $agentInfo, new IncludedVolumesSettings([]));
    }

    public function getVolumesFromKey(string $agentKey): Volumes
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $agentInfo = $agentConfig->getRaw('agentInfo', '');
        $includedVolumesSettings = $this->includedVolumesKeyService->loadFromKey($agentKey);
        return $this->getVolumesFromKeyContents($agentKey, $agentInfo, $includedVolumesSettings);
    }

    public function getVolumesFromKeyContents(string $agentKey, string $keyContents, IncludedVolumesSettings $includedVolumesSettings): Volumes
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $isShadowsnap = $agentConfig->isShadowsnap();
        return $this
            ->volumesCollector
            ->collectVolumesFromAssocArray(
                $this->volumeNormalizer->normalizeVolumesArrayFromAgentInfo(
                    $keyContents,
                    $isShadowsnap,
                    $includedVolumesSettings
                )
            );
    }

    /**
     * Set up the default included volumes configuration for the agent associated with this service.
     * This should only be called during pairing for initial setup of includes.
     *
     * @param Agent $agent
     */
    public function setupDefaultIncludes(Agent $agent): void
    {
        $this->logger->setAssetContext($agent->getKeyName());
        $this->logger->debug('VSV0009 Setting up initial included volumes.');
        foreach ($agent->getVolumes() as $volume) {
            if ($volume->getMountpoint() && $volume->getMountpoint() !== self::SWAP_MOUNTPOINT) {
                $agent->getIncludedVolumesSettings()->add($volume->getGuid());
                $this->logger->debug('VSV0010 Including default volume', ['volume' => $volume->toArray()]);
            }
        }
        $this->includedVolumesKeyService->saveToKey($agent->getKeyName(), $agent->getIncludedVolumesSettings());
    }

    public function includeByGuid(string $agentKey, string $volumeGuid): bool
    {
        $this->logger->setAssetContext($agentKey);
        try {
            $includedVolumeSettings = $this->includedVolumesKeyService->loadFromKey($agentKey);
            $includedVolumeSettings->add($volumeGuid);
            $this->includedVolumesKeyService->saveToKey($agentKey, $includedVolumeSettings);
            $this->logger->info('VSV0001 Successfully included volume by guid', ['guid' => $volumeGuid]); // log code is used by device-web see DWI-2252
            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'VSV0002 Could not include volume matching the GUID',
                [
                    'agentKey' => $agentKey,
                    'volumeGUID' => $volumeGuid,
                    'exception' => $e
                ]
            );

            throw new Exception('Could not include volume matching the GUID', null, $e);
        }
    }

    public function isIncluded(string $agentKey, string $volumeGuid): bool
    {
        return in_array($volumeGuid, $this->includedVolumesKeyService->loadFromKey($agentKey)->getIncludedList());
    }

    public function excludeByGuid(string $agentKey, string $volumeGuid): bool
    {
        $this->logger->setAssetContext($agentKey);
        try {
            $includedVolumeSettings = $this->includedVolumesKeyService->loadFromKey($agentKey);
            $includedVolumeSettings->remove($volumeGuid);
            $this->includedVolumesKeyService->saveToKey($agentKey, $includedVolumeSettings);
            // log code is used by device-web see DWI-2252
            $this->logger->info(
                'VSV0005 Successfully excluded volume by guid',
                [
                    'agentKey' => $agentKey,
                    'excludedVolumeGUID' => $volumeGuid,
                    'newIncludedVolumeGuidsList' => $includedVolumeSettings->getIncludedList()
                ]
            );
            return true;
        } catch (\Exception $e) {
            $this->logger->error('VSV0006 Could not exclude volume matching the GUID', ['guid' => $volumeGuid, 'exception' => $e]);

            throw new Exception('Could not find volume to exclude', null, $e);
        }
    }

    /**
     * @return VolumeMetadata[]
     */
    public function getAllMissingVolumeMetadata(string $agentKey): array
    {
        /** @var VolumeMetadata[] $missingVolumes */
        $missingVolumes = [];
        $volumes = $this->getVolumesFromKey($agentKey);
        $recoveryPoints = explode(
            "\n",
            $this->agentConfigFactory->create($agentKey)->getRaw('recoveryPoints', '')
        );
        $includedVolumes = $this->getIncludedVolumeMetaSettings($agentKey, $recoveryPoints);
        foreach ($includedVolumes->getVolumeMetadata() as $volumeMetadata) {
            if ($volumes->getVolumeByGuid($volumeMetadata->getGuid()) === null) {
                $missingVolumes[] = $volumeMetadata;
            }
        }
        return $missingVolumes;
    }

    public function getIncludedVolumeMetaSettings(string $agentKey, array $recoveryPoints): IncludedVolumesMetaSettings
    {
        $includedVolumeMetaSettings = [];
        $volumes = $this->getVolumesFromKey($agentKey);
        $includedGuidList = $this->includedVolumesKeyService->loadFromKey($agentKey)->getIncludedList();
        foreach ($includedGuidList as $includedGuid) {
            $volume = $volumes->getVolumeByGuid($includedGuid);
            if ($volume === null) {
                $repositoryVolume = $this->getVolumeFromSnapshotRepositoryByGuid($agentKey, $recoveryPoints, $includedGuid);
                $mountpoint = $repositoryVolume->getMountpoint();
            } else {
                $mountpoint = $volume->getMountpoint();
            }
            $includedVolumeMetaSettings[] = new VolumeMetadata($mountpoint, $includedGuid);
        }

        return new IncludedVolumesMetaSettings($includedVolumeMetaSettings);
    }

    /**
     * Return all volumes for an agent and any relevant information about the volume. If the volume is missing,
     * then certain information (space, isOS, etc.) are unobtainable as they are not stored anywhere.
     *
     * @param Agent $agent
     * @return array[] An array of associative arrays, each of which contains the following keys: guid, path, space,
     * isOs, isSys, isRemovable, filesystem, included, backupsExist, isMissing
     */
    public function getVolumeParameters(Agent $agent): array
    {
        $missingVolumes = $this->getAllMissingVolumes($agent->getKeyName());
        $agentVolumes = $agent->getVolumes();
        $backupsExist = count($agent->getLocal()->getRecoveryPoints()->getAll())
            + count($agent->getOffsite()->getRecoveryPoints()->getAll()) > 0;
        $volumeParameters = [];
        foreach ($missingVolumes as $missingVolume) {
            $volumeGuid = $missingVolume->getGuid();
            $volumeMountpoint = $missingVolume->getMountpoint();

            if ($volumeMountpoint === self::SWAP_MOUNTPOINT) {
                // swap volume is hidden
                continue;
            }

            $volumeParameter = [];
            $volumeParameter['guid'] = $volumeGuid;
            $volumeParameter['path'] = $volumeMountpoint;
            $volumeParameter['backupsExist'] = $backupsExist;
            $volumeParameter['datasetExists'] = $this->doesDatasetExist($agent, $volumeGuid);
            $volumeParameter['isMissing'] = true;
            $volumeParameter['included'] = true;
            $volumeParameter['mountpointsArray'] = $missingVolume->getMountpointsArray();
            $volumeParameters[] = $volumeParameter;
        }

        foreach ($agentVolumes as $volume) {
            $volumeGuid = $volume->getGuid();
            $volumeMountpoint = $volume->getMountpoint();

            $isSwapVolume = $volumeMountpoint === self::SWAP_MOUNTPOINT;
            $hasNoMountpoint = empty($volumeMountpoint);
            if ($isSwapVolume || $hasNoMountpoint) {
                // swap volume and volumes without mountpoints are hidden
                continue;
            }

            $volumeParameter = [];
            $volumeParameter['guid'] = $volumeGuid;
            $volumeParameter['path'] = $volumeMountpoint;
            $volumeParameter['backupsExist'] = $backupsExist;
            $volumeParameter['datasetExists'] = $this->doesDatasetExist($agent, $volumeGuid);
            $volumeParameter['isMissing'] = false;
            $volumeParameter['space'] = [
                'used' => ByteUnit::BYTE()->toGiB($volume->getSpaceUsed()),
                'free' => ByteUnit::BYTE()->toGiB($volume->getSpaceFree()),
                'total' => ByteUnit::BYTE()->toGiB($volume->getSpaceTotal()),
            ];
            $volumeParameter['isOs'] = $volume->isOsVolume();
            $volumeParameter['isSys'] = $volume->isSysVolume();
            $volumeParameter['isRemovable'] = $volume->isRemovable();
            $volumeParameter['filesystem'] = $volume->getFilesystem();
            $volumeParameter['included'] = $volume->isIncluded();
            $volumeParameter['mountpointsArray'] = $volume->getMountpointsArray();
            $volumeParameters[] = $volumeParameter;
        }

        return $volumeParameters;
    }

    /**
     * Get included volume settings
     *
     * @param Agent $agent
     * @return array of volume models
     */
    public function getIncludedVolumesParameters(Agent $agent): array
    {
        $volumeParameters = $this->getVolumeParameters($agent);

        $includedVolumes = array_filter(
            $volumeParameters,
            function (array $vol) {
                return $vol['included'];
            }
        );

        return $includedVolumes;
    }

    public function generateVoltabArray(Volumes $volumes, IncludedVolumesSettings $includedVolumesSettings): array
    {
        $voltab = [];
        foreach ($includedVolumesSettings->getIncludedList() as $guid) {
            $voltab[] = [
                'label' => $volumes[$guid]->getLabel(),
                'uuid' => $volumes[$guid]->getGuid(),
                'fstype' => $volumes[$guid]->getFilesystem(),
                'size' => $volumes[$guid]->getSpaceTotal(),
                'mountpoint' => $volumes[$guid]->getMountpoint(),
                'device' => $volumes[$guid]->getBlockDevice()
            ];
        }
        return $voltab;
    }

    /**
     * Delete the volume's local dataset
     * CALLING THIS FUNCTION WILL DESTROY DATA. Use with caution. Do not run while a snapshot is in progress.
     *
     * @param Agent $agent
     * @param string $volumeGuid
     */
    public function destroyVolumeDatasetByGuid(Agent $agent, string $volumeGuid): void
    {
        $deletionKey = sprintf(VolumesService::DELETING_DATASET_KEY_PATTERN, $volumeGuid);
        $deleteAfterRollbackKey = sprintf(VolumesService::DELETE_AFTER_ROLLBACK_KEY_PATTERN, $volumeGuid);
        $agentConfig = $this->agentConfigFactory->create($agent->getKeyName());
        if ($agentConfig->has($deletionKey)) {
            $this->logger->warning(
                'VSV0013 Volume is already being deleted',
                [
                    'agentKey' => $agent->getKeyName(),
                    'guid' => $volumeGuid
                ]
            );
            return;
        }
        $agentConfig->set($deletionKey, true);

        if (!$agentConfig->has($deleteAfterRollbackKey)) {
            $agentConfig->set($deleteAfterRollbackKey, true);
        }

        try {
            $volumeDatasetFiles = $this->getVolumeDatasetFiles($agent, $volumeGuid);

            if (empty($volumeDatasetFiles)) {
                $this->logger->warning(
                    'VSV0014 Volume has no images in live dataset to delete',
                    [
                        'agentKey' => $agent->getKeyName(),
                        'guid' => $volumeGuid
                    ]
                );
            } else {
                foreach ($volumeDatasetFiles as $file) {
                    $this->filesystem->unlink($file);
                }
            }
        } finally {
            $agentConfig->clear($deletionKey);
        }
    }

    /**
     * Checks whether a volume's dataset is currently being deleted
     *
     * @param string $agentKey
     * @param string $volumeGuid GUID of the volume to check
     * @return bool whether or not the volume's dataset is currently being deleted
     */
    public function isDatasetDeletionInProgress(string $agentKey, string $volumeGuid): bool
    {
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        return $agentConfig->has(sprintf(VolumesService::DELETING_DATASET_KEY_PATTERN, $volumeGuid));
    }


    public function getIncludedTotalVolumeSizeInBytes(Volumes $volumes): int
    {
        $includedVolumes = new Volumes();

        foreach ($volumes as $volume) {
            if ($volume->isIncluded()) {
                $includedVolumes->addVolume($volume);
            }
        }

        return $this->getTotalVolumeSizeInBytes($includedVolumes);
    }

    public function getTotalVolumeSizeInBytes(Volumes $volumes): int
    {
        $totalVolumeSize = 0;

        foreach ($volumes as $volume) {
            $totalVolumeSize += $volume->getSpaceTotal();
        }

        return $totalVolumeSize;
    }

    public function getVolumeMetadataByLabelOrGuid(string $agentKey, string $volumeIdentifier): VolumeMetadata
    {
        $agentVolumes = $this->getVolumesFromKeyNoIncludes($agentKey);

        foreach ($agentVolumes as $volume) {
            $volumeGuid = $volume->getGuid();
            $guidMatch = $volumeGuid === $volumeIdentifier;
            $volumeName = $volume->getMountpoint() ?: $volume->getLabel();
            $nameMatch = $volumeName === $volumeIdentifier;
            if ($guidMatch || $nameMatch) {
                return new VolumeMetadata($volumeName, $volumeGuid);
            }
        }

        throw new Exception('Could not find volume matching the identifier');
    }

    /**
     * Returns an array containing dataset files for the volume with the given GUID.
     *
     * @param Agent $agent
     * @param string $volumeGuid
     * @return array contains a list of dataset files found for the given volume GUID
     */
    private function getVolumeDatasetFiles(Agent $agent, string $volumeGuid): array
    {
        $agentDatasetPath = $agent->getDataset()->getLiveDatasetBasePath() . '/' . $agent->getKeyName();

        $datasetFiles = $this->filesystem->scandir($agentDatasetPath);
        $volumeDatasetFiles = [];

        foreach ($datasetFiles as $file) {
            if (preg_match("/^$volumeGuid/", $file)) {
                $volumeDatasetFiles[] = "$agentDatasetPath/$file";
                continue;
            }
            if (preg_match("/.vmdk$/", $file)) {
                $contents = $this->filesystem->fileGetContents("$agentDatasetPath/$file");
                if (strpos($contents, $volumeGuid) !== false) {
                    $volumeDatasetFiles[] = "$agentDatasetPath/$file";
                }
            }
        }

        return $volumeDatasetFiles;
    }

    private function doesDatasetExist(Agent $agent, string $volumeGuid)
    {
        return !empty($this->getVolumeDatasetFiles($agent, $volumeGuid));
    }

    private function getAllMissingVolumes(string $agentKey): Volumes
    {
        $missingVolumes = new Volumes([]);
        $volumes = $this->getVolumesFromKey($agentKey);
        $recoveryPoints = explode(
            "\n",
            $this->agentConfigFactory->create($agentKey)->getRaw('recoveryPoints', '')
        );
        $includedVolumes = $this->getIncludedVolumes($agentKey, $recoveryPoints);
        foreach ($includedVolumes->getArrayCopy() as $includedVolume) {
            if ($volumes->getVolumeByGuid($includedVolume->getGuid()) === null) {
                $missingVolumes->addVolume($includedVolume);
            }
        }
        return $missingVolumes;
    }

    private function getIncludedVolumes(string $agentKey, array $recoveryPoints): Volumes
    {
        $includedVolumes = new Volumes([]);
        $volumes = $this->getVolumesFromKey($agentKey);
        $includedGuidList = $this->includedVolumesKeyService->loadFromKey($agentKey)->getIncludedList();
        foreach ($includedGuidList as $includedGuid) {
            $volume = $volumes->getVolumeByGuid($includedGuid);
            if ($volume === null) {
                $includedVolumes->addVolume(
                    $this->getVolumeFromSnapshotRepositoryByGuid(
                        $agentKey,
                        $recoveryPoints,
                        $includedGuid
                    )
                );
                continue;
            }
            $includedVolumes->addVolume($volume);
        }

        return $includedVolumes;
    }

    private function getVolumeFromSnapshotRepositoryByGuid(
        string $agentKey,
        array $recoveryPoints,
        string $guid
    ): Volume {
        rsort($recoveryPoints);
        foreach ($recoveryPoints as $recoveryPoint) {
            if (empty($recoveryPoint)) {
                continue;
            }
            $recoveryPoint = intval($recoveryPoint);
            $recoveryPointVolumes = $this->agentSnapshotRepository->getVolumes($agentKey, $recoveryPoint);
            $recoveryPointVolume = $recoveryPointVolumes->getVolumeByGuid($guid);
            if ($recoveryPointVolume !== null) {
                return $recoveryPointVolume;
            }
        }
        // no volume has been found in the local snapshot repository with included guid, create a fake one
        return $this->getVolumeOnlyGuidAvailable($guid);
    }

    private function getVolumeOnlyGuidAvailable(string $guid): Volume
    {
        return new Volume(
            0,
            $guid,
            '',
            0,
            0,
            0,
            0,
            $guid,
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            true,
            [$guid]
        );
    }
}
