<?php

namespace Datto\Restore;

use Datto\Asset\Agent\MountHelper;
use Datto\Log\LoggerFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Verification\VerificationService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Removes explicitly excluded files from DTC restores.
 *
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class FileExclusionService
{
    /*
     * File contents look like:
     * <drive-uuid>.exclusions.json
     * {
     *     "hasdata": [
     *         "/Program Files/Datto/Datto Direct to Cloud/DattoDirectToCloud.exe"
     *     ],
     *     "nodata": [
     *         "/pagefile.sys"
     *     ]
     * }
     */
    const EXCLUSIONS_FILE_EXTENSION = '.exclusions.json';
    const TEMP_MOUNT_PATH_FORMAT = '/tmp/%s';
    const MKDIR_MODE = 0777;

    /** @var LoggerFactory */
    private $loggerFactory;

    /** @var MountHelper */
    private $mountHelper;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        LoggerFactory $loggerFactory,
        MountHelper $mountHelper,
        Filesystem $filesystem
    ) {
        $this->loggerFactory = $loggerFactory;
        $this->mountHelper = $mountHelper;
        $this->filesystem = $filesystem;
    }

    /**
     * Checks for exclusion files. If present mounts the datasets, removes the files, and unmounts the datasets.
     *
     * @param CloneSpec $cloneSpec
     */
    public function exclude(CloneSpec $cloneSpec, bool $alwaysExcludeHasDataFiles = false)
    {
        $logger = $this->loggerFactory->getAsset($cloneSpec->getAssetKey());
        $glob = $this->filesystem->join($cloneSpec->getTargetMountpoint(), '*' . self::EXCLUSIONS_FILE_EXTENSION);
        $exclusionFiles = $this->filesystem->glob($glob);

        // Only mount the volumes if something needs to be excluded
        if (empty($exclusionFiles)) {
            $logger->debug('FEX0003 No exclusion files found, skipping ...');
            return;
        }

        $suffix = $cloneSpec->getSuffix();
        $tempMountpoint = sprintf(self::TEMP_MOUNT_PATH_FORMAT, $cloneSpec->getTargetDatasetShortName());
        $this->filesystem->mkdirIfNotExists($tempMountpoint, false, self::MKDIR_MODE);

        $mountedVolumes = $this->mountHelper->mountTree(
            $cloneSpec->getAssetKey(),
            $cloneSpec->getTargetMountpoint(),
            $tempMountpoint,
            false
        );

        // Iterate through all volumes and check if an exclusion file exists for it
        foreach ($mountedVolumes as $mountedVolume) {
            $exclusionFile = $mountedVolume->getVolume()->getGuid() . self::EXCLUSIONS_FILE_EXTENSION;
            $exclusionFile = $this->filesystem->join($cloneSpec->getTargetMountpoint(), $exclusionFile);
            if (in_array($exclusionFile, $exclusionFiles, true)) {
                $exclusions = json_decode($this->filesystem->fileGetContents($exclusionFile), true);
                $nodata = $exclusions['nodata'] ?? [];
                $hasdata = $exclusions['hasdata'] ?? [];

                // nodata files should always be removed; hasdata files only need to be removed from virts

                $isRestoreTypeSupported = in_array(
                    $suffix,
                    [
                        RestoreType::ACTIVE_VIRT,
                        RestoreType::RESCUE,
                        VerificationService::VERIFICATION_SUFFIX,
                        RestoreType::FILE,
                        RestoreType::VHD,
                        RestoreType::DIFFERENTIAL_ROLLBACK
                    ],
                    true
                );

                if (!$isRestoreTypeSupported) {
                    throw new Exception("Excluding files for restore type $suffix is unsupported.");
                }

                $shouldRemoveHasData = $alwaysExcludeHasDataFiles ||
                    in_array(
                        $suffix,
                        [
                            RestoreType::ACTIVE_VIRT,
                            RestoreType::RESCUE,
                            VerificationService::VERIFICATION_SUFFIX
                        ],
                        true
                    );

                if ($shouldRemoveHasData) {
                    $this->removeFiles($logger, $mountedVolume->getMountPath(), $hasdata);
                }

                $this->removeFiles($logger, $mountedVolume->getMountPath(), $nodata);
            } else {
                $logger->debug("FEX0001 No exclusion file $exclusionFile, skipping ...");
            }
        }
        $this->mountHelper->unmountTree($tempMountpoint, $cloneSpec->getTargetMountpoint());
        $this->filesystem->rmdir($tempMountpoint);
    }

    /**
     * @param DeviceLoggerInterface $logger
     * @param string $baseDir
     * @param string[] $relativePaths
     */
    private function removeFiles(DeviceLoggerInterface $logger, string $baseDir, array $relativePaths)
    {
        $removed = 0;
        $total = count($relativePaths);

        foreach ($relativePaths as $file) {
            $fullPath = $this->filesystem->join($baseDir, $file);
            if ($this->filesystem->unlinkDir($fullPath)) {
                $removed++;
            }
        }

        $logger->info('FEX0002 Removed file exclusions successfully', ['removed' => $removed, 'total' => $total]);
    }
}
