<?php

namespace Datto\OwnCloud;

use Datto\Asset\RecoveryPoint\DestroySnapshotReason;
use Datto\Cloud\SpeedSync;
use Datto\Common\Resource\ProcessFactory;
use Datto\Feature\FeatureService;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\ZFS\ZfsDatasetService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Class for managing OwnCloud storage and backups
 *
 * @author Andrew Cope <acope@datto.com>
 */
class OwnCloudStorage implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const OFFSITE_CONTROL_FILE = "/datto/config/keys/owncloud.offsiteControl";
    private const OFFSITE_CONTROL_FILE_CONTENTS = '{"interval":"86400","latestSnapshot":"0","latestOffsiteSnapshot":"0"}';
    private const OWNCLOUD_DATASET = "homePool/home/owncloud";
    private const OWNCLOUD_MOUNT_POINT = "/datto/owncloud";

    private Filesystem $filesystem;
    private SpeedSync $speedSync;
    private FeatureService $featureService;
    private DateTimeService $dateTimeService;
    private ProcessFactory $processFactory;
    private ZfsDatasetService $zfsDatasetService;

    public function __construct(
        Filesystem $filesystem,
        SpeedSync $speedSync,
        FeatureService $featureService,
        DateTimeService $dateTimeService,
        ProcessFactory $processFactory,
        ZfsDatasetService $zfsDatasetService
    ) {
        $this->filesystem = $filesystem;
        $this->speedSync = $speedSync;
        $this->featureService = $featureService;
        $this->dateTimeService = $dateTimeService;
        $this->processFactory = $processFactory;
        $this->zfsDatasetService = $zfsDatasetService;
    }

    /**
     * Destroy the ownCloud storage and all of its contents
     */
    public function destroyStorage(): void
    {
        try {
            $dataset = $this->zfsDatasetService->getDataset(self::OWNCLOUD_DATASET);
            /**
             * @psalm-suppress RedundantCondition
             */
            if ($dataset) {
                $this->logger->info('OCS0002 Destroying owncloud dataset');
                $this->zfsDatasetService->destroyDataset($dataset, true);
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('OCS0012 Could not destroy owncloud storage', ['exception' => $throwable]);
        }
    }

    /**
     * Deletes offsite dataset for DattoDrive/OwnCloud.
     */
    public function destroyOffsiteStorage(): bool
    {
        try {
            $this->logger->debug(
                'OCS0013 Destroying Owncloud/DattoDrive remote datasets',
                ['zfsPath' => self::OWNCLOUD_DATASET]
            );

            $this->speedSync->remoteDestroy(self::OWNCLOUD_DATASET, DestroySnapshotReason::MANUAL());
        } catch (Throwable $e) {
            $this->logger->error(
                'OCS0014 Error while attempting to destroy Owncloud/DattoDrive remote dataset',
                ['zfsPath' => self::OWNCLOUD_DATASET, 'exception' => $e]
            );
            return false;
        }
        return true;
    }

    /**
     * Get whether or not there is a dataset for ownCloud
     */
    public function hasStorageAllocated(): bool
    {
        return $this->zfsDatasetService->exists(self::OWNCLOUD_DATASET);
    }

    /**
     * Get the mountpoint for owncloud storage
     * @return string
     */
    public function getMountpoint(): string
    {
        return $this->zfsDatasetService->getDataset(self::OWNCLOUD_DATASET)->getMountPoint();
    }

    /**
     * Get the directory where a user's files reside
     * @param string $user
     * @return string
     */
    public function getUserStorageDir(string $user): string
    {
        return $this->getMountpoint() . '/data/' . $user;
    }

    /**
     * Deletes the data associated with a given user
     *
     * @param string $user
     */
    public function deleteUserData(string $user): void
    {
        $this->filesystem->unlinkDir($this->getUserStorageDir($user));
    }
}
