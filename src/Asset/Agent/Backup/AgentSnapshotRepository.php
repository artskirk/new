<?php

namespace Datto\Asset\Agent\Backup;

use Datto\Asset\Agent\Backup\Serializer\OperatingSystemSerializer;
use Datto\Asset\Agent\Backup\Serializer\DiskDriveSerializer;
use Datto\Asset\Agent\IncludedVolumesKeyService;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\Agent\VolumesCollector;
use Datto\Asset\Agent\VolumesNormalizer;
use Datto\Common\Utility\Filesystem;
use SplFileInfo;

/**
 * Reads from ZFS snapshots
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class AgentSnapshotRepository
{
    const KEY_AGENTINFO_TEMPLATE = '%s.agentInfo';
    const KEY_INCLUDE_TEMPLATE = 'config/%s.include';
    const KEY_VOLTAB_TEMPLATE = 'voltab';
    const KEY_DISKTAB_TEMPLATE = '%s.diskDrives';
    const KEY_VMX_FILE_NAME = 'configuration.vmx';
    const KEY_AZURE_VM_METADATA_TEMPLATE = '%s/AzureVmMetadata';

    const BACKUP_DIRECTORY_TEMPLATE = '/home/agents/%s/.zfs/snapshot/%d';

    private Filesystem $filesystem;
    private OperatingSystemSerializer $operatingSystemSerializer;
    private DiskDriveSerializer $diskDriveSerializer;
    private IncludedVolumesKeyService $includedVolumesKeyService;
    private VolumesCollector $volumesCollector;
    private VolumesNormalizer $volumeNormalizer;

    public function __construct(
        Filesystem $filesystem,
        OperatingSystemSerializer $operatingSystemSerializer,
        DiskDriveSerializer $diskDriveSerializer,
        IncludedVolumesKeyService $includedVolumesKeyService,
        VolumesNormalizer $volumeNormalizer,
        VolumesCollector $volumesCollector
    ) {
        $this->filesystem = $filesystem;
        $this->operatingSystemSerializer = $operatingSystemSerializer;
        $this->diskDriveSerializer = $diskDriveSerializer;
        $this->includedVolumesKeyService = $includedVolumesKeyService;
        $this->volumeNormalizer = $volumeNormalizer;
        $this->volumesCollector = $volumesCollector;
    }

    public function getVolumes(string $assetKey, int $snapshot): Volumes
    {
        $agentInfo = $this->getKeyContents($assetKey, $snapshot, sprintf(self::KEY_AGENTINFO_TEMPLATE, $assetKey));

        if ($agentInfo === false) {
            return new Volumes([]);
        }

        $agentInfo = unserialize($agentInfo) ?: [];
        if (empty($agentInfo['volumes'])) {
            return new Volumes([]);
        }

        return $this->volumesCollector->collectVolumesFromAssocArray($agentInfo['volumes']);
    }

    public function getDesiredVolumes(string $assetKey, int $snapshot): ?IncludedVolumesSettings
    {
        $includes = $this->getKeyContents($assetKey, $snapshot, sprintf(self::KEY_INCLUDE_TEMPLATE, $assetKey));
        $agentInfo = $this->getKeyContents($assetKey, $snapshot, sprintf(self::KEY_AGENTINFO_TEMPLATE, $assetKey));

        if ($includes === false || $agentInfo === false) {
            return null;
        }

        return $this->includedVolumesKeyService->loadFromKeyContents($assetKey, $agentInfo, $includes);
    }

    public function getProtectedVolumes(string $assetKey, int $snapshot): Volumes
    {
        $allVolumes = $this->getVolumes($assetKey, $snapshot);
        $allVolumes = $allVolumes ?: new Volumes([]);
        $includedVolumesSettings = $this->getDesiredVolumes($assetKey, $snapshot);
        $includedVolumesSettings = $includedVolumesSettings ?: new IncludedVolumesSettings([]);
        $protectedVolumes = new Volumes([]);
        foreach ($includedVolumesSettings->getIncludedList() as $includedGuid) {
            $volume = $allVolumes->getVolumeByGuid($includedGuid);
            if ($volume !== null) {
                $protectedVolumes->addVolume($volume);
            }
        }
        return $protectedVolumes;
    }

    /**
     * Returns all known disk drives in the agent.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @return DiskDrive[]|null
     */
    public function getDiskDrives(string $assetKey, int $snapshot): ?array
    {
        $disktab = $this->getKeyContents(
            $assetKey,
            $snapshot,
            sprintf(self::KEY_DISKTAB_TEMPLATE, $assetKey)
        );

        if ($disktab === false) {
            return null;
        }

        return $this->diskDriveSerializer->unserialize($disktab);
    }

    /**
     * Returns the operating system of the backup repository.
     *
     * @param string $assetKey
     * @param int $snapshot
     * @return OperatingSystem|null
     */
    public function getOperatingSystem(string $assetKey, int $snapshot): ?OperatingSystem
    {
        $agentInfo = $this->getKeyContents($assetKey, $snapshot, sprintf(self::KEY_AGENTINFO_TEMPLATE, $assetKey));

        if ($agentInfo === false) {
            return null;
        }

        return $this->operatingSystemSerializer->unserialize($agentInfo);
    }

    /**
     * Reads the given key file corresponding to the given epoch and returns its contents.
     *
     * @param string $assetKey
     * @param int $epoch
     * @param string $keyFile
     * @return string|false
     */
    public function getKeyContents(string $assetKey, int $epoch, string $keyFile)
    {
        $info = $this->getKeyInfo($assetKey, $epoch, $keyFile);

        // suppress warning if file does not exist
        return @$this->filesystem->fileGetContents($info->getRealPath());
    }

    /**
     * Get info about the key file
     *
     * @param string $assetKey
     * @param int $epoch
     * @param string $keyFile
     * @return SplFileInfo
     */
    public function getKeyInfo(string $assetKey, int $epoch, string $keyFile): SplFileInfo
    {
        $snapDir = sprintf(
            self::BACKUP_DIRECTORY_TEMPLATE,
            $assetKey,
            $epoch
        );

        $filePath = sprintf('%s/%s', $snapDir, $keyFile);

        return new SplFileInfo($filePath);
    }
}
