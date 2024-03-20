<?php

namespace Datto\Restore\Export;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\Backup\AgentSnapshotService;
use Datto\Asset\Agent\Volume;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\ImageExport\Status;
use Datto\Restore\CloneSpec;
use Datto\Restore\Export\Serializers\StatusSerializer;
use Datto\Restore\Export\Usb\UsbDrive;
use Exception;

/**
 * This class encapsulates the minimum required information for an image export.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class Context
{
    const STATUS_FILE = 'status.json';

    /** @var Filesystem */
    protected $filesystem;

    /** @var Agent */
    private $agent;

    /** @var int */
    private $snapshot;

    /** @var AbstractFuseOverlayMount */
    private $fuseOverlayMount;

    /** @var ImageType */
    private $type;

    /** @var StatusSerializer */
    private $serializer;

    /** @var BootType */
    private $bootType;

    /** @var AgentSnapshotService */
    private $agentSnapshotService;

    /** @var int */
    private $diskSizeAlignToBytes = 0;

    /** @var array */
    private $sasUriMap = [];

    /** @var string[] */
    private $exportedFiles;

    /** @var CloneSpec */
    private $cloneSpec;

    /** @var string */
    private $statusId;

    /** @var bool */
    private $enableAgentInRestoredVm;

    public function __construct(
        Agent $agent,
        int $snapshot,
        ImageType $type,
        AbstractFuseOverlayMount $fuseOverlayMount,
        bool $enableAgentInRestoredVm,
        BootType $bootType = null,
        Filesystem $filesystem = null,
        StatusSerializer $statusSerializer = null,
        AgentSnapshotService $agentSnapshotService = null
    ) {
        $this->agent = $agent;
        $this->snapshot = $snapshot;
        $this->type = $type;
        $this->fuseOverlayMount = $fuseOverlayMount;
        $this->bootType = $bootType ?: BootType::BIOS();
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->serializer = $statusSerializer ?: new StatusSerializer();
        $this->agentSnapshotService = $agentSnapshotService ?: new AgentSnapshotService();
        $this->cloneSpec = CloneSpec::fromAsset($agent, $snapshot, $type->value());
        $this->enableAgentInRestoredVm = $enableAgentInRestoredVm;
    }

    /**
     * @return Agent
     */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * Get the final mountpoint used for exports.
     *
     * Since all export types are using FUSE overlay mounts, this mountpoint
     * should be used for any post-processing - i.e. point SMB/NFS shares at it,
     * delete "unwanted" files etc.
     *
     * @return string
     */
    public function getMountPoint()
    {
        return $this->fuseOverlayMount->getFuseMountPath($this->getCloneMountPoint());
    }

    public function getSnapshot(): int
    {
        return $this->snapshot;
    }

    /**
     * Get the list of volumes to use.
     *
     * @return Volume[]
     */
    public function getVolumes(): array
    {
        $agentSnapshot = $this->agentSnapshotService->get($this->getAgent()->getKeyName(), $this->snapshot);
        $volumes = $agentSnapshot->getVolumes()->getArrayCopy();
        if ($volumes === null) {
            throw new Exception("No volumes found");
        }
        return $volumes;
    }

    public function hasOsVolume(): bool
    {
        return $this->agent->isSupportedOperatingSystem();
    }

    public function getOsVolume(): Volume
    {
        $volumes = $this->getVolumes();
        foreach ($volumes as $volume) {
            if ($volume->isOsVolume()) {
                return $volume;
            }
        }

        throw new Exception('OS volume not found');
    }

    /**
     * Get the 'real' mount point for the snapshot.
     *
     * @return string
     */
    public function getCloneMountPoint()
    {
        return $this->cloneSpec->getTargetMountpoint();
    }

    /**
     * @return ImageType
     */
    public function getImageType()
    {
        return $this->type;
    }

    /**
     * @return BootType
     */
    public function getBootType()
    {
        return $this->bootType;
    }

    /**
     * Set the status of an export.
     *
     * This can later be extended to include the progress of an export.
     *
     * @param Status $status
     */
    public function setStatus(Status $status)
    {
        $statusContents = $this->serializer->serialize($status);

        if (!$this->filesystem->filePutContents($this->getStatusPath(), json_encode($statusContents))) {
            throw new Exception('Unable to write export status');
        }
    }

    /**
     * Get the status of the export.
     *
     * @return Status
     */
    public function getStatus()
    {
        $statusContents = @json_decode($this->filesystem->fileGetContents($this->getStatusPath()), true);

        if (!$statusContents) {
            throw new Exception('Unable to read export status');
        }

        return $this->serializer->unserialize($statusContents);
    }

    /**
     * Get the instance that handles FUSE overlay filesystems.
     *
     * Currently, this could be either TransparentMount or StitchfsMount
     *
     * @return AbstractFuseOverlayMount
     */
    public function getFuseOverlayMount(): AbstractFuseOverlayMount
    {
        return $this->fuseOverlayMount;
    }

    /**
     * Whether or not this is a network export.
     *
     * @return bool
     */
    public function isNetworkExport(): bool
    {
        return true;
    }

    /**
     * Save the path and size of the USB disk.
     *
     * @param string $disk
     * @param int $size
     */
    public function setUsbInformation(string $disk, int $size)
    {
        throw new Exception("Not supported for this export type");
    }

    /**
     * Get USB drive information.  Not supported for network export
     *
     * @return UsbDrive
     */
    public function getUsbInformation(): UsbDrive
    {
        throw new Exception("Not supported for this export type");
    }

    /**
     * Gets the user cancellation state for a long running export process.
     *
     * @return bool True if the user cancelled the process
     */
    public function isCancelled(): bool
    {
        return false;
    }

    public function getCloneSpec(): CloneSpec
    {
        return $this->cloneSpec;
    }

    /**
     * Get the path to the export status file.
     *
     * @return string
     */
    private function getStatusPath()
    {
        return $this->getCloneMountPoint() . '/' . self::STATUS_FILE;
    }

    /**
     * Additional disk alignment needed for a given export.
     *
     * Some export types require additional alignment/padding added regardless
     * of the image format used.
     *
     * @return int size in bytes
     */
    public function getDiskSizeAlignToBytes(): int
    {
        return $this->diskSizeAlignToBytes;
    }

    public function setDiskSizeAlignToBytes(int $diskSizeAlignToBytes)
    {
        $this->diskSizeAlignToBytes = $diskSizeAlignToBytes;
    }

    public function setSasUriMap(array $sasUriMap)
    {
        $this->sasUriMap = $sasUriMap;
    }

    public function getSasUriMap(): array
    {
        return $this->sasUriMap;
    }

    public function setExportedFiles(array $exportedFiles)
    {
        $this->exportedFiles = $exportedFiles;
    }

    public function getExportedFiles(): array
    {
        return $this->exportedFiles;
    }

    public function getStatusId(): string
    {
        return $this->statusId;
    }

    public function setStatusId(string $statusId)
    {
        $this->statusId = $statusId;
    }

    public function getEnableAgentInRestoredVm(): bool
    {
        return $this->enableAgentInRestoredVm;
    }
}
