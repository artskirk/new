<?php

namespace Datto\Asset\Agent;

use Datto\Asset\Asset;
use Datto\Asset\AssetService;
use Datto\Asset\Share\Iscsi\IscsiShare;
use Datto\Asset\Share\Nas\NasShare;
use Datto\Asset\Share\ExternalNas\ExternalNasShare;
use Datto\Block\LoopManager;
use Datto\Config\AgentConfigFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Restore\File\FileRestoreService;
use Datto\System\Mount;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\RestoreType;
use Datto\System\MountManager;
use Datto\Util\DateTimeZoneService;
use Psr\Log\LoggerAwareInterface;
use Exception;

class MountHelper implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const UNSUPPORTED_FILESYSTEMS = [
        Volume::FILESYSTEM_REFS
    ];

    /**
     * How many times to try unmount operation before giving up
     */
    const UNMOUNT_ATTEMPTS = 5;

    private Filesystem $filesystem;
    private MountLoopHelper $mountLoopHelper;
    private MountPointHelper $mountPointHelper;
    private MountManager $mountManager;
    private DmCryptManager $dmCrypt;
    private AssetService $assetService;
    private DateTimeZoneService $dateTimeZoneService;
    private LoopManager $loopManager;
    private AgentConfigFactory $agentConfigFactory;
    private VolumesService $volumesService;
    private IncludedVolumesKeyService $includedVolumesKeyService;

    public function __construct(
        Filesystem $filesystem,
        MountLoopHelper $mountLoopHelper,
        MountPointHelper $mountPointHelper,
        MountManager $mountManager,
        DmCryptManager $dmCrypt,
        AssetService $assetService,
        DateTimeZoneService $dateTimeZoneService,
        LoopManager $loopManager,
        AgentConfigFactory $agentConfigFactory,
        VolumesService $volumesService,
        IncludedVolumesKeyService $includedVolumesKeyService
    ) {
        $this->filesystem = $filesystem;
        $this->mountPointHelper = $mountPointHelper;
        $this->mountManager = $mountManager;
        $this->dmCrypt = $dmCrypt;
        $this->assetService = $assetService;
        $this->dateTimeZoneService = $dateTimeZoneService;
        $this->loopManager = $loopManager;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->mountLoopHelper = $mountLoopHelper;
        $this->volumesService = $volumesService;
        $this->includedVolumesKeyService = $includedVolumesKeyService;
    }

    /**
     * Mount the images from the given directory to the specified target.
     *
     * @param string $assetName
     * @param string $srcDir Source directory containing images (.datto/.detto) and a voltab or agentInfo file
     * @param string $destDir Target directory to mount images to
     * @param bool $readOnly
     * @return MountedVolume[] volumes that were actually mounted
     */
    public function mountTree($assetName, $srcDir, $destDir, $readOnly = true): array
    {
        $this->logger->debug(
            'MNT0007 Mounting images',
            ['asset' => $assetName, 'src' => $srcDir, 'dest' =>$destDir]
        );

        $agentConfig = $this->agentConfigFactory->create($assetName);
        $encrypted = $agentConfig->has("encryption");

        $agentInfoPath = $srcDir . "/" . $assetName . ".agentInfo";
        if (!$this->filesystem->exists($agentInfoPath)) {
            throw new Exception("Cannot find an agentInfo file in $srcDir");
        }

        $agentInfoContents = $this->filesystem->fileGetContents($agentInfoPath);
        $includeKeyPath = $srcDir . "/config/" . $assetName . ".include";
        if (!$this->filesystem->exists($includeKeyPath)) {
            // Some (old) snapshots will be missing the config dir and include key, assume all volumes included
            $includedGuids = [];
            $fakeVolumes =
                $this->volumesService->getVolumesFromKeyContents(
                    $assetName,
                    $agentInfoContents,
                    new IncludedVolumesSettings([])
                );
            /** @var Volume $volume */
            foreach ($fakeVolumes->getArrayCopy() as $volume) {
                $includedGuids[] = $volume->getGuid();
            }
            $includedVolumeSettings = new IncludedVolumesSettings($includedGuids);
        } else {
            $includeKeyContents = $this->filesystem->fileGetContents($includeKeyPath);
            $includedVolumeSettings = $this->includedVolumesKeyService->loadFromKeyContents(
                $assetName,
                $agentInfoContents,
                $includeKeyContents
            );
        }
        $allVolumes =
            $this->volumesService->getVolumesFromKeyContents(
                $assetName,
                $agentInfoContents,
                $includedVolumeSettings
            );
        $mountableVolumes = new Volumes([]);
        // Windows has a 'System Reserved' partition with no mountpoint, we don't want to restore that.
        /** @var Volume $volume */
        foreach ($allVolumes->getArrayCopy() as $volume) {
            if ($volume->getMountpoint() == '' ||
                in_array($volume->getFilesystem(), self::UNSUPPORTED_FILESYSTEMS) ||
                !$volume->isIncluded()
            ) {
                continue;
            }
            $mountableVolumes->addVolume($volume);
        }

        // create target directory if it doesn't exist
        if (!$this->filesystem->isDir($destDir)) {
            $this->logger->debug('MNT0006 Creating mountpoint', ['dir' => $destDir]);
            $this->filesystem->mkdir($destDir, true, 0775);
        }

        // sort volumes for mounting
        $mountableVolumes = $this->sortMounts($mountableVolumes);

        $loopMap = $this->mountLoopHelper->attachLoopDevices($assetName, $srcDir, $encrypted);

        return $this->mountPointHelper->mountAll($mountableVolumes, $loopMap, $destDir, $readOnly);
    }

    /**
     * Unmounts all files associated with a restore
     *
     * @param string $assetName
     * @param string $snapEpoch
     * @param string $suffix
     */
    public function unmount($assetName, $snapEpoch, $suffix): void
    {
        $asset = $this->assetService->get($assetName);

        if ($suffix == RestoreType::FILE) {
            if ($asset instanceof NasShare || $asset instanceof ExternalNasShare) {
                $this->unmountNasShare($assetName, $snapEpoch, $suffix);
            } elseif ($asset instanceof IscsiShare) {
                // not supported.
                throw new Exception('iSCSI shares do not have mounts.');
            } else {
                // TODO: move logic for generating directories to private method unmountTree()
                $dateFormat = $this->dateTimeZoneService->localizedDateFormat('time-date-hyphenated');
                $date = date($dateFormat, $snapEpoch);

                $destDir = Asset::BASE_MOUNT_PATH . '/' . $assetName . '/' . $date;
                $cloneDir = sprintf(FileRestoreService::MOUNT_POINT_TEMPLATE, $assetName, $snapEpoch, $suffix);

                $this->unmountTree($destDir, $cloneDir);
                $this->detachEncryptedImages($cloneDir);
            }
        }
    }

    private function sortMounts(Volumes $volumes): Volumes
    {
        $volumesArray = $volumes->getArrayCopy();
        uasort($volumesArray, function (Volume $a, Volume $b) {
            return $a->getMountpoint() <=> $b->getMountpoint();
        });

        return new Volumes($volumesArray);
    }

    /**
     * Unmounts a Nas Share restore
     *
     * @param string $assetName
     * @param string $snapEpoch
     * @param string $suffix
     */
    private function unmountNasShare($assetName, $snapEpoch, $suffix): void
    {
        $dir = Asset::BASE_MOUNT_PATH . '/' . $assetName . '-' . $snapEpoch . '-' . $suffix;

        if (!$this->mountManager->isMounted($dir)) {
            return;
        }

        try {
            $this->mountManager->unmount($dir);
            $this->filesystem->unlinkDir($dir);
        } catch (Exception $ex) {
            throw new Exception("Failed to unmount directory for NAS Share: $dir");
        }
    }

    /**
     * Unmount a tree that was mounted with mountTree()
     *
     * @param string $destDir Mount directory
     * @param string|bool $cloneDir Cloned directory containing image files
     * @TODO Make private, use unmount instead
     */
    public function unmountTree($destDir, $cloneDir = false): void
    {
        // unmount filesystems and take names of devices
        $mounts = $this->mountManager->getMounts();

        $this->logger->debug('MNT0005 Unmounting agent images', ['cloneDir' => $cloneDir, 'destDir' => $destDir]);

        $dirQuoted = preg_quote($destDir, ';');

        /** @var Mount[] $mountpoints */
        $mountpoints = [];
        foreach ($mounts as $mount) {
            if (preg_match(";^$dirQuoted(/|\$);", $mount->getMountPoint())) {
                $mountpoints[] = $mount;
            }
        }

        // sort mountpoints in reverse
        usort($mountpoints, function (Mount $a, Mount $b) {
            return strcmp($b->getMountPoint(), $a->getMountPoint());
        });

        // unmount anything that's mounted
        foreach ($mountpoints as $vol) {
            $this->logger->debug('MNT0004 umount fs', ['mountpoint' => $vol->getMountPoint()]);

            $isSuccessful = $this->mountPointHelper->unmountSingle($vol->getMountPoint(), self::UNMOUNT_ATTEMPTS);

            if (!$isSuccessful) {
                $this->logger->warning('MNT0003 FAILED to umount fs', ['mountpoint' => $vol->getMountPoint()]);
                continue;
            }

            $this->filesystem->rmdir($vol->getMountPoint());
        }

        if ($cloneDir) {
            $this->mountLoopHelper->detachLoopDevices($cloneDir);
        }
        $this->logger->debug('MNT0002 unmountTree complete');
    }

    /**
     * Detach encrypted images based on the given mount point.
     *
     * @param string $mountPoint the mount point where the detto files live.
     */
    private function detachEncryptedImages($mountPoint): void
    {
        foreach ($this->filesystem->glob("$mountPoint/*.detto") as $imageFile) {
            $this->dmCrypt->detach($imageFile);
        }
    }
}
