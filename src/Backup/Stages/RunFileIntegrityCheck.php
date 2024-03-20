<?php

namespace Datto\Backup\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\Asset;
use Datto\Backup\BackupStatusService;
use Datto\Backup\Integrity\FilesystemIntegrity;
use Datto\Filesystem\FilesystemCheckResult;
use Exception;
use Throwable;

/**
 * This backup stage runs filesystem integrity checks and cleanup.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RunFileIntegrityCheck extends BackupStage
{
    const FILESYSTEM_INTEGRITY_CLONE_SUFFIX = 'filesystem-integrity';

    private FilesystemIntegrity $filesystemIntegrity;

    public function __construct(FilesystemIntegrity $filesystemIntegrity)
    {
        $this->filesystemIntegrity = $filesystemIntegrity;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        /** @var Agent $asset */
        $asset = $this->context->getAsset();

        try {
            $this->context->updateBackupStatus(BackupStatusService::STATE_FILESYSTEM_INTEGRITY);
            $this->runFilesystemCheck($asset);
            $this->context->updateBackupStatus(BackupStatusService::STATE_POST);
        } catch (Throwable $throwable) {
            $this->context->getLogger()->error(
                'BAK0035 File integrity check failed: ' .
                $throwable->getMessage()
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * Runs the filesystem integrity check on the Asset dataset clone for the given snapshot epoch.
     */
    private function runFilesystemCheck(Agent $agent): void
    {
        try {
            $filesystemCheckResults = $this->filesystemIntegrity->verifyIntegrity(
                $agent,
                $this->context->getLocalVerificationDattoImages(),
                $this->getLatestSnapshot($agent),
                $this->context->getIncludedVolumeGuids()
            );

            $this->context->setFilesystemCheckResults($filesystemCheckResults);
        } catch (Throwable $throwable) {
            $this->context->getLogger()->error(
                'BAK0025 Could not perform filesystem integrity checks: ' .
                $throwable->getMessage()
            );
        }
    }

    /**
     * Get the most recent snapshot for the current asset.
     *
     * @param Asset $asset
     * @return int Epoch time of the most recent snapshot
     */
    private function getLatestSnapshot(Asset $asset): int
    {
        $latestRecoveryPoint = $asset->getLocal()->getRecoveryPoints()->getLast();
        if (is_null($latestRecoveryPoint)) {
            throw new Exception("No local snapshots exist for asset {$asset->getKeyName()}");
        }
        return $latestRecoveryPoint->getEpoch();
    }
}
