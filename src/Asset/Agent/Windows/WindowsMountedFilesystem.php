<?php

namespace Datto\Asset\Agent\Windows;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DattoImage;
use Datto\Asset\Agent\DattoImageFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreType;
use Datto\System\MountManager;
use Datto\Log\DeviceLoggerInterface;
use Exception;
use Throwable;

/**
 * Represents a single mounted Windows filesystem.
 * This handles encrypted volumes transparently.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class WindowsMountedFilesystem
{
    const MKDIR_MODE = 0777;

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var Filesystem */
    private $filesystem;

    /** @var MountManager */
    private $mountManager;

    /** @var DattoImageFactory */
    private $dattoImageFactory;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var CloneSpec */
    private $cloneSpec;

    /** @var DattoImage */
    private $osImage;

    /** @var string */
    private $mountPoint;

    /**
     * @param AssetCloneManager $assetCloneManager
     * @param Filesystem $filesystem
     * @param MountManager $mountManager
     * @param DattoImageFactory $dattoImageFactory
     * @param DeviceLoggerInterface $logger
     */
    public function __construct(
        AssetCloneManager $assetCloneManager,
        Filesystem $filesystem,
        MountManager $mountManager,
        DattoImageFactory $dattoImageFactory,
        DeviceLoggerInterface $logger
    ) {
        $this->assetCloneManager = $assetCloneManager;
        $this->filesystem = $filesystem;
        $this->mountManager = $mountManager;
        $this->dattoImageFactory = $dattoImageFactory;
        $this->logger = $logger;

        $this->cloneSpec = null;
        $this->osImage = null;
        $this->mountPoint = null;
    }

    /**
     * Ensures that the filesystem is unmounted, all block devices are torn down, and the snapshot clone is removed.
     * This is a failsafe in case unmountOsDriveForSnapshot() doesn't get called for some reason.
     */
    public function __destruct()
    {
        $this->unmountOsDriveForSnapshot();
    }

    /**
     * Create a clone directory of the given snapshot, setup the required block devices, and mount the OS volume.
     *
     * @param Agent $agent
     * @param int $snapshotEpoch
     * @param string $mountPoint
     */
    public function mountOsDriveFromSnapshot(Agent $agent, int $snapshotEpoch, string $mountPoint): void
    {
        $this->logger->setAssetContext($agent->getKeyName());
        if (!is_null($this->cloneSpec) || !is_null($this->osImage) || !is_null($this->mountPoint)) {
            throw new Exception("An OS drive from another snapshot is already mounted $this->mountPoint");
        }

        // TODO: Replace this block with a call to DattoImageSnapshot->setupDattoImages
        $this->cloneSpec = CloneSpec::fromAsset($agent, $snapshotEpoch, RestoreType::WINDOWS_FILESYSTEM);
        if ($this->assetCloneManager->exists($this->cloneSpec)) {
            throw new Exception("A clone already exists for this {$agent->getKeyName()}@{$snapshotEpoch}");
        }

        $this->assetCloneManager->cleanOrphanedClones($agent->getKeyName(), RestoreType::WINDOWS_FILESYSTEM);

        $this->logger->info('WMF0002 Mounting Windows filesystem', ['snapshotEpoch' => $snapshotEpoch, 'mountpoint' => $mountPoint]);

        $ensureDecrypted = false;  // DattoImage handles the image/volume decryption, clone manager shouldn't.
        $this->assetCloneManager->createClone($this->cloneSpec, $ensureDecrypted);

        $volumeImages = $this->dattoImageFactory->createImagesForSnapshot(
            $agent,
            $snapshotEpoch,
            $this->cloneSpec->getTargetMountpoint()
        );

        $this->osImage = $this->getOsImage($volumeImages);
        $this->osImage->acquire($readOnly = true, $runPartitionScan = true);

        $partitionPath = $this->osImage->getPathToPartition();
        $this->mountPartition($partitionPath, $mountPoint);
        $this->mountPoint = $mountPoint;
    }

    /**
     * Unmount the filesystem, tear down all block devices, and cleanup the snapshot clone.
     * If there is no mounted filesystem, block device, and snapshot clone this function will be a no-op.
     * This will not generate an exception if an error occurs.
     */
    public function unmountOsDriveForSnapshot(): void
    {
        try {
            if (!is_null($this->mountPoint)) {
                $this->unmountPartition($this->mountPoint);
                $this->mountPoint = null;
            }
            // TODO: Replace this block with a call to DattoImageSnapshot->cleanupDattoImages
            if (!is_null($this->osImage)) {
                $this->osImage->release();
                $this->osImage = null;
            }
            if (!is_null($this->cloneSpec)) {
                $this->assetCloneManager->destroyClone($this->cloneSpec);
                $this->cloneSpec = null;
            }
        } catch (Throwable $throwable) {
            $this->logger->error('WMF0003 Failed to properly cleanup!', ['exception' => $throwable]);
        }
    }

    /**
     * Mount the given volume partition.
     *
     * @param string $partitionPath
     * @param string $mountPoint
     */
    private function mountPartition(string $partitionPath, string $mountPoint): void
    {
        // Make sure the mountpoint exists
        if (!$this->filesystem->exists($mountPoint)) {
            $this->logger->debug("WMF0030 Creating mount point directory $mountPoint");
            if (!$this->filesystem->mkdir($mountPoint, true, self::MKDIR_MODE)) {
                throw new Exception("Unable to create injection mount point $mountPoint");
            }
        }

        // Mount the partition
        $this->logger->debug("WMF0031 Mounting $partitionPath to $mountPoint");
        $mountResult = $this->mountManager->mountDevice($partitionPath, $mountPoint);
        if ($mountResult->mountFailed()) {
            $this->logger->critical('WMF0033 Failed to mount partition', ['mountpoint' => $mountPoint, 'mountResult' => $mountResult->getMountOutput()]);
            $this->unmountPartition($mountPoint);
            throw new Exception("Unable to mount $partitionPath to $mountPoint");
        }
    }

    /**
     * Unmount the given mounted filesystem.
     *
     * @param string $mountPoint
     */
    private function unmountPartition(string $mountPoint): void
    {
        try {
            $this->mountManager->unmount($mountPoint);
        } catch (Throwable $throwable) {
            $this->logger->error('WMF0034 Failed to unmount partition', ['mountpoint' => $mountPoint]);
        }

        if ($this->filesystem->exists($mountPoint)) {
            $this->logger->debug("WMF0032 Removing mount point directory $mountPoint");
            if (!$this->filesystem->rmdir($mountPoint)) {
                throw new Exception("Unable to remove mount point $mountPoint");
            }
        }
    }

    /**
     * Determine which volume is the OS drive.
     *
     * @param DattoImage[] $volumeImages
     * @return DattoImage
     */
    private function getOsImage(array $volumeImages): DattoImage
    {
        foreach ($volumeImages as $image) {
            if ($image->getVolume()->isOsVolume()) {
                return $image;
            }
        }

        throw new Exception('Agent has no OS volume');
    }
}
