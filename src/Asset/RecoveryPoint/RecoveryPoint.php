<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Asset\ApplicationResult;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\RansomwareResults;
use Datto\Asset\VerificationScreenshotResult;
use Datto\Asset\VerificationScriptsResults;
use Datto\Filesystem\FilesystemCheckResult;

/**
 * Holds all cached information about a recovery point
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class RecoveryPoint
{
    // These constants are used by Continuity Audit (index.html.twig and pdf.html.twig)
    const UNSUCCESSFUL_SCREENSHOT = 0;
    const SUCCESSFUL_SCREENSHOT = 1;
    const NO_SCREENSHOT = 2;
    const SCREENSHOT_QUEUED = 3;
    const SCREENSHOT_INPROGRESS = 4;
    const SCREENSHOT_NOT_IN_QUEUE = 5;

    // there is code that depends on these being lowercase.
    const VOLUME_BACKUP_TYPE_FULL = 'full';
    const VOLUME_BACKUP_TYPE_DIFFERENTIAL = 'differential';
    const VOLUME_BACKUP_TYPE_INCREMENTAL = 'incremental';

    /**
     * timestamp
     *
     * @var int $epoch
     */
    protected $epoch;

    /**
     * Ransomware results
     *
     * @var ?RansomwareResults
     */
    protected $ransomwareResults;

    /**
     * Verification Scripts Results
     *
     * @var VerificationScriptsResults
     */
    protected $verificationScriptsResults;

    /** @var VolumeMetadata[] */
    protected $missingVolumes;

    /** @var array */
    protected $volumeBackupTypes;

    /** @var string|null */
    protected $engineUsed;

    /** @var string|null */
    protected $engineConfigured;

    /** @var int|null */
    protected $deletionTime;

    /** @var string|null */
    protected $deletionReason;

    /** @var FilesystemCheckResult[] */
    protected $filesystemCheckResults;

    /** @var ApplicationResult[] */
    protected $applicationResults;

    /** @var ApplicationResult[] */
    protected $serviceResults;

    /** @var VerificationScreenshotResult */
    protected $verificationScreenshotResult;

    /** @var bool|null */
    protected $backupForced;

    /** @var bool|null */
    protected $osUpdatePending;

    /**
     * @param int $epoch
     * @param RansomwareResults|null $ransomwareResults
     * @param VerificationScriptsResults|null $verificationScriptsResults
     * @param array|null $volumeBackupTypes
     *      Volume backup types is a mapping between volume GUIDs and the associated backup type (eg. full vs.
     *      incremental).
     * @param VolumeMetadata[] $missingVolumes
     * @param string|null $engineUsed the method used for the backup, either VSS, DBD, or STC
     * @param string|null $engineConfigured the engine configured for usage
     * @param int|null $deletionTime the time the point was deleted
     * @param string|null $deletionReason source of deletion ("manual"/"retention")
     * @param FilesystemCheckResult[] $filesystemCheckResults
     * @param ApplicationResult[] $applicationResults
     * @param ApplicationResult[] $serviceResults
     * @param VerificationScreenshotResult|null $verificationScreenshotResult
     * @param bool|null $backupForced
     * @param bool|null $osUpdatePending
     */
    public function __construct(
        int $epoch,
        RansomwareResults $ransomwareResults = null,
        VerificationScriptsResults $verificationScriptsResults = null,
        array $volumeBackupTypes = null,
        array $missingVolumes = [],
        string $engineUsed = null,
        string $engineConfigured = null,
        int $deletionTime = null,
        string $deletionReason = null,
        array $filesystemCheckResults = [],
        array $applicationResults = [],
        array $serviceResults = [],
        VerificationScreenshotResult $verificationScreenshotResult = null,
        bool $backupForced = null,
        bool $osUpdatePending = null
    ) {
        $this->epoch = $epoch;
        $this->ransomwareResults = $ransomwareResults;

        // Initialize this as not all recovery points have metadata, but getting this may be attempted.
        $this->verificationScriptsResults = $verificationScriptsResults ?: new VerificationScriptsResults(false);
        $this->missingVolumes = $missingVolumes ?: [];

        // Additional metadata
        $this->volumeBackupTypes = $volumeBackupTypes ?: [];
        $this->engineUsed = $engineUsed;
        $this->engineConfigured = $engineConfigured;
        $this->deletionTime = $deletionTime;
        $this->deletionReason = $deletionReason;
        $this->filesystemCheckResults = $filesystemCheckResults ?: [];
        $this->applicationResults = $applicationResults;
        $this->serviceResults = $serviceResults;
        $this->verificationScreenshotResult = $verificationScreenshotResult;
        $this->backupForced = $backupForced;
        $this->osUpdatePending = $osUpdatePending;
    }

    /**
     * @return ?RansomwareResults
     */
    public function getRansomwareResults(): ?RansomwareResults
    {
        return $this->ransomwareResults;
    }

    /**
     * @param RansomwareResults $ransomwareResults
     */
    public function setRansomwareResults($ransomwareResults): void
    {
        $this->ransomwareResults = $ransomwareResults;
    }

    /**
     * @return VerificationScriptsResults
     */
    public function getVerificationScriptsResults()
    {
        return $this->verificationScriptsResults;
    }

    /**
     * @param VerificationScriptsResults $verificationScriptsResults
     */
    public function setVerificationScriptsResults(VerificationScriptsResults $verificationScriptsResults): void
    {
        $this->verificationScriptsResults = $verificationScriptsResults;
    }

    /**
     * Reset verification script results.
     */
    public function resetAdvancedVerificationResults(): void
    {
        $this->verificationScriptsResults = new VerificationScriptsResults(false);
        $this->applicationResults = [];
        $this->serviceResults = [];
    }

    /**
     * @return int
     */
    public function getEpoch()
    {
        return $this->epoch;
    }

    /**
     * @param array $volumeBackupTypes
     */
    public function setVolumeBackupTypes(array $volumeBackupTypes): void
    {
        $this->volumeBackupTypes = $volumeBackupTypes;
    }

    /**
     * @return array
     */
    public function getVolumeBackupTypes()
    {
        return $this->volumeBackupTypes;
    }

    /**
     * @return VolumeMetadata[]
     */
    public function getMissingVolumes(): array
    {
        return $this->missingVolumes;
    }

    /**
     * @param VolumeMetadata[] $missingVolumes
     */
    public function setMissingVolumes(array $missingVolumes): void
    {
        $this->missingVolumes = $missingVolumes;
    }

    /**
     * @return string|null
     */
    public function getEngineUsed()
    {
        return $this->engineUsed;
    }

    /**
     * @param null|string $engineUsed
     */
    public function setEngineUsed(string $engineUsed = null): void
    {
        $this->engineUsed = $engineUsed;
    }

    /**
     * @return null|string
     */
    public function getEngineConfigured()
    {
        return $this->engineConfigured;
    }

    /**
     * @param null|string $engineConfigured
     */
    public function setEngineConfigured(string $engineConfigured = null): void
    {
        $this->engineConfigured = $engineConfigured;
    }

    /**
     * @param int|null $deletionTime
     */
    public function setDeletionTime(int $deletionTime = null): void
    {
        $this->deletionTime = $deletionTime;
    }

    /**
     * @return int|null
     */
    public function getDeletionTime()
    {
        return $this->deletionTime;
    }

    /**
     * @return bool
     */
    public function isDeleted(): bool
    {
        return !is_null($this->deletionTime);
    }

    /**
     * @param string $deletionReason
     */
    public function setDeletionReason(string $deletionReason): void
    {
        $this->deletionReason = $deletionReason;
    }

    /**
     * @return string|null
     */
    public function getDeletionReason()
    {
        return $this->deletionReason;
    }

    /**
     * @return FilesystemCheckResult[]
     */
    public function getFilesystemCheckResults(): array
    {
        return $this->filesystemCheckResults;
    }

    /**
     * @param FilesystemCheckResult[] $filesystemCheckResults
     */
    public function setFilesystemCheckResults(array $filesystemCheckResults): void
    {
        $this->filesystemCheckResults = $filesystemCheckResults;
    }

    /**
     * @return ApplicationResult[]
     */
    public function getApplicationResults(): array
    {
        return $this->applicationResults;
    }

    /**
     * @param ApplicationResult[] $applicationResults
     */
    public function setApplicationResults(array $applicationResults): void
    {
        $this->applicationResults = $applicationResults;
    }

    /**
     * @return ApplicationResult[]
     */
    public function getServiceResults(): array
    {
        return $this->serviceResults;
    }

    /**
     * @param ApplicationResult[] $serviceResults
     */
    public function setServiceResults(array $serviceResults): void
    {
        $this->serviceResults = $serviceResults;
    }

    /**
     * @return VerificationScreenshotResult|null
     */
    public function getVerificationScreenshotResult()
    {
        return $this->verificationScreenshotResult;
    }

    /**
     * @param VerificationScreenshotResult $verificationScreenshotResult
     */
    public function setVerificationScreenshotResult(VerificationScreenshotResult $verificationScreenshotResult): void
    {
        $this->verificationScreenshotResult = $verificationScreenshotResult;
    }

    /**
     * @return bool|null
     */
    public function wasBackupForced()
    {
        return $this->backupForced;
    }

    /**
     * @param bool $backupForced
     */
    public function setBackupForced(bool $backupForced): void
    {
        $this->backupForced = $backupForced;
    }

    /**
     * @return bool|null
     */
    public function wasOsUpdatePending()
    {
        return $this->osUpdatePending;
    }

    /**
     * @param bool $osUpdatePending
     */
    public function setOsUpdatePending(bool $osUpdatePending): void
    {
        $this->osUpdatePending = $osUpdatePending;
    }
}
