<?php

namespace Datto\Asset\RecoveryPoint;

use Datto\Asset\Agent\Volume;
use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\Agent\Volumes;
use Datto\Asset\ApplicationResult;
use Datto\Asset\RansomwareResults;
use Datto\Asset\VerificationScriptOutput;
use Datto\Asset\VerificationScriptsResults;
use Datto\Restore\Restore;

/**
 * Holds all cached and environmental information about a recovery point. If you're
 * looking for the class that is serialized to disk look at RecoveryPoint.php
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class RecoveryPointInfo extends RecoveryPoint
{
    /** @var bool */
    private $existsLocally;

    /** @var bool */
    private $existsOffsite;

    /** @var bool */
    private $isCritical;

    /** @var int */
    private $screenshotStatus;

    /** @var int */
    private $transferSize;

    /** @var int */
    private $localUsedSize;

    /** @var int */
    private $offsiteStatus;

    /** @var Restore[] */
    private $localRestores;

    /** @var Restore[] */
    private $offsiteRestores;

    /** @var FilesystemIntegritySummary */
    private $filesystemIntegritySummary;

    /** @var int */
    private $remainingScreenshotTime;

    /** @var bool */
    private $hasLocalVerificationError;

    /** @var bool */
    private $engineFailedOver;

    /** @var bool */
    private $hasRestores;

    /** @var bool */
    private $hasScreenshotPending;

    /** @var bool */
    private $hasScreenshotVerificationError;

    /** @var bool */
    private $hasApplicationVerificationError;

    /** @var bool */
    private $hasServiceVerificationError;

    /** @var bool */
    private $hasAdvancedVerificationError;

    private Volumes $volumes;

    private string $backupTimeFormatted;
    private string $deletionTimeFormatted;

    public function __construct(
        int $epoch,
        bool $existsLocally,
        bool $existsOffsite,
        bool $isCritical,
        int $screenshotStatus,
        int $offsiteStatus,
        int $transferSize,
        int $localUsedSize,
        array $localRestores,
        array $offsiteRestores,
        array $volumeBackupTypes,
        string $backupTimeFormatted,
        FilesystemIntegritySummary $filesystemIntegritySummary,
        RansomwareResults $ransomwareResults = null,
        VerificationScriptsResults $verificationScriptsScreenshotResults = null,
        int $remainingScreenshotTime = 0,
        array $missingVolumes = [],
        bool $hasLocalVerificationError = false,
        string $engineUsed = null,
        string $engineConfigured = null,
        bool $engineFailedOver = false,
        bool $hasRestores = false,
        bool $hasScreenshotPending = false,
        int $deletionTime = null,
        string $deletionTimeFormatted = '',
        string $deletionReason = null,
        array $filesystemCheckResults = [],
        bool $hasScreenshotVerificationError = false,
        array $applicationResults = [],
        bool $hasApplicationVerificationError = false,
        array $serviceResults = [],
        bool $hasServiceVerificationError = false,
        bool $hasAdvancedVerificationError = false,
        bool $backupForced = null,
        bool $osUpdatePending = null,
        Volumes $volumes = null
    ) {
        parent::__construct(
            $epoch,
            $ransomwareResults,
            $verificationScriptsScreenshotResults,
            $volumeBackupTypes,
            $missingVolumes,
            $engineUsed,
            $engineConfigured,
            $deletionTime,
            $deletionReason,
            $filesystemCheckResults,
            $applicationResults,
            $serviceResults,
            null,
            $backupForced,
            $osUpdatePending
        );

        $this->deletionTimeFormatted = $deletionTimeFormatted;
        $this->backupTimeFormatted = $backupTimeFormatted;
        $this->existsLocally = $existsLocally;
        $this->existsOffsite = $existsOffsite;
        $this->isCritical = $isCritical;
        $this->screenshotStatus = $screenshotStatus;
        $this->offsiteStatus = $offsiteStatus;
        $this->transferSize = $transferSize;
        $this->localUsedSize = $localUsedSize;
        $this->localRestores = $localRestores;
        $this->offsiteRestores = $offsiteRestores;
        $this->filesystemIntegritySummary = $filesystemIntegritySummary;
        $this->remainingScreenshotTime = $remainingScreenshotTime;
        $this->hasLocalVerificationError = $hasLocalVerificationError;
        $this->engineFailedOver = $engineFailedOver;
        $this->hasRestores = $hasRestores;
        $this->hasScreenshotPending = $hasScreenshotPending;
        $this->hasScreenshotVerificationError = $hasScreenshotVerificationError;
        $this->hasApplicationVerificationError = $hasApplicationVerificationError;
        $this->hasServiceVerificationError = $hasServiceVerificationError;
        $this->hasAdvancedVerificationError = $hasAdvancedVerificationError;
        $this->volumes = $volumes ?? new Volumes([]);
    }

    public function hasRestores(): bool
    {
        return $this->hasRestores;
    }

    public function hasScreenshotPending(): bool
    {
        return $this->hasScreenshotPending;
    }

    public function hasLocalVerificationError(): bool
    {
        return $this->hasLocalVerificationError;
    }

    /**
     * @return FilesystemIntegritySummary
     */
    public function getFilesystemIntegritySummary(): FilesystemIntegritySummary
    {
        return $this->filesystemIntegritySummary;
    }

    public function getRemainingScreenshotTime(): int
    {
        return $this->remainingScreenshotTime;
    }

    public function hasRansomwareResults(): bool
    {
        return isset($this->ransomwareResults);
    }

    public function getScreenshotStatus(): int
    {
        return $this->screenshotStatus;
    }

    public function getTransferSize(): int
    {
        return $this->transferSize;
    }

    public function getLocalUsedSize(): int
    {
        return $this->localUsedSize;
    }

    public function existsLocally(): bool
    {
        return $this->existsLocally;
    }

    public function existsOffsite(): bool
    {
        return $this->existsOffsite;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    public function getOffsiteStatus(): int
    {
        return $this->offsiteStatus;
    }

    /**
     * @return Restore[]
     */
    public function getLocalRestores(): array
    {
        return $this->localRestores;
    }

    /**
     * @return Restore[]
     */
    public function getOffsiteRestores(): array
    {
        return $this->offsiteRestores;
    }

    public function hasLocalRestore(): bool
    {
        return !empty($this->localRestores);
    }

    public function hasOffsiteRestore(): bool
    {
        return !empty($this->offsiteRestores);
    }

    /**
     * Determine the worst backup type from all volumes included in this recovery-point. By "worst", we're
     * referring to the amount of storage that will be consumed by the backup. A full backup is worse than an
     * incremental since it will consume a greater amount of storage.
     *
     * @return string|null
     */
    public function getWorstVolumeBackupType()
    {
        $types = [
            RecoveryPoint::VOLUME_BACKUP_TYPE_FULL => 0,
            RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL => 0,
            RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL => 0
        ];

        foreach ($this->volumeBackupTypes as $volumeBackupType) {
            $types[$volumeBackupType]++;
        }

        if ($types[RecoveryPoint::VOLUME_BACKUP_TYPE_FULL] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_FULL;
        } elseif ($types[RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL;
        } elseif ($types[RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL] > 0) {
            return RecoveryPoint::VOLUME_BACKUP_TYPE_INCREMENTAL;
        } else {
            return null;
        }
    }

    private function getDiffMergeDetails(): array
    {
        $includedVolumes = array_filter($this->volumes->getArrayCopy(), function (Volume $volume) {
            return $volume->isIncluded();
        });

        $mountPoints = [];
        foreach ($this->volumeBackupTypes as $guid => $volumeBackupType) {
            if ($volumeBackupType !== RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL) {
                continue;
            }

            foreach ($includedVolumes as $volume) {
                if ($volume->getGuid() === $guid) {
                    $mountPoints[] = $volume->getMountpoint();
                }
            }
        }

        $isPartial = count($includedVolumes) !== count($mountPoints);

        return [
            'isPartial' => $isPartial,
            'volumeNames' => $mountPoints
        ];
    }

    /**
     * @return ApplicationResult[]
     */
    public function getApplicationResults(): array
    {
        return $this->applicationResults;
    }

    /**
     * @return string
     */
    public function getBackupTimeFormatted(): string
    {
        return $this->backupTimeFormatted;
    }

    /**
     * @return string
     */
    public function getDeletionTimeFormatted(): string
    {
        return $this->deletionTimeFormatted;
    }

    public function toArray(): array
    {
        return [
            'snapshotEpoch' => $this->getEpoch(),
            'existsLocally' => $this->existsLocally(),
            'existsOffsite' => $this->existsOffsite(),
            'offsiteStatus' => $this->getOffsiteStatus(),
            'screenshotStatus' => $this->getScreenshotStatus(),
            'localRestores' => $this->getLocalRestores(),
            'offsiteRestores' => $this->getOffsiteRestores(),
            'isCritical' => $this->isCritical(),
            'remainingScreenshotTime' => $this->getRemainingScreenshotTime(),
            'worstVolumeBackupType' => $this->getWorstVolumeBackupType(),
            'backupTimeFormatted' => $this->getBackupTimeFormatted(),
            'diffMergeDetails' => $this->getDiffMergeDetails(),
            'localUsedSize' => $this->getLocalUsedSize(),
            'hasRestores' => $this->hasRestores(),
            'hasScreenshotPending' => $this->hasScreenshotPending(),
            'deletionTime' => $this->getDeletionTime(),
            'deletionTimeFormatted' => $this->getDeletionTimeFormatted(),
            'isDeleted' => $this->isDeleted(),
            'deletionReason' => $this->getDeletionReason(),
            'verification' => [
                'local' => $this->getLocalVerificationResultsAsArray(),
                'advanced' => $this->getAdvancedVerificationResultsAsArray(),
            ],
            'osUpdatePending' => $this->osUpdatePending
        ];
    }

    public function getLocalVerificationResultsAsArray(): array
    {
        return [
            // Overall Error (Consumed by BMR/RapidRollback)
            'hasError' => $this->hasLocalVerificationError,

            'ransomwareResults' => $this->getRansomwareResultsAsArray(),
            'missingVolumes' => $this->getVolumesMetadataAsArray($this->missingVolumes),
            'engineFailedOver' => $this->engineFailedOver,
            'engineUsed' => $this->engineUsed,
            'engineConfigured' => $this->engineConfigured,
            'filesystemIntegrity' => $this->getFilesystemIntegritySummaryAsArray(),
            'hasAllHealthyFilesystems' => $this->filesystemIntegritySummary->hasAllHealthy(),
        ];
    }

    private function getAdvancedVerificationResultsAsArray(): array
    {
        return [
            // Overall Error
            'hasError' => $this->hasAdvancedVerificationError,

            'hasScreenshotVerificationError' => $this->hasScreenshotVerificationError,
            'hasApplicationVerificationError' => $this->hasApplicationVerificationError,
            'hasServiceVerificationError' => $this->hasServiceVerificationError,
            'applicationResults' => $this->formatApplicationResultsAsArray($this->applicationResults),
            'serviceResults' => $this->formatApplicationResultsAsArray($this->serviceResults),
            'scriptResults' => $this->getVerificationScriptResultsAsArray(),
        ];
    }

    private function getFilesystemIntegritySummaryAsArray(): array
    {
        $integritySummary = $this->filesystemIntegritySummary;

        return [
            'healthy' => $this->getVolumesMetadataAsArray($integritySummary->getHealthy()),
            'warning' => $this->getVolumesMetadataAsArray($integritySummary->getWarning()),
            'error' => $this->getVolumesMetadataAsArray($integritySummary->getError()),
            'critical' => $this->getVolumesMetadataAsArray($integritySummary->getCritical()),
            'processFailed' => $this->getVolumesMetadataAsArray($integritySummary->getProcessFailed()),
            'processTimeout' => $this->getVolumesMetadataAsArray($integritySummary->getProcessTimeout()),
            'ntfsErrors' => $this->getNtfsErrorDataAsArray($integritySummary->getNtfsErrors())
        ];
    }

    private function getVerificationScriptResultsAsArray(): array
    {
        $verificationScriptsResultsArray = [];

        if ($this->verificationScriptsResults) {
            foreach ($this->verificationScriptsResults->getOutput() as $output) {
                $isComplete = $output->getState() === VerificationScriptOutput::SCRIPT_COMPLETE;

                $verificationScriptsResultsArray[] = [
                    'name' => $output->getScriptName(),
                    'displayName' => $output->getScriptDisplayName(),
                    'state' => $output->getState(),
                    'output' => $output->getOutput(),
                    'error' => $isComplete ? $output->getExitCode() !== 0 : null,
                ];
            }
        }

        return $verificationScriptsResultsArray;
    }

    private function getRansomwareResultsAsArray(): array
    {
        return [
            'error'  => $this->ransomwareResults !== null && $this->ransomwareResults->hasRansomware(),
            'output' => $this->ransomwareResults !== null ? $this->ransomwareResults->getExceptionMessage() : ''
        ];
    }

    /**
     * @param VolumeMetadata[] $volumesMetadata
     */
    public function getVolumesMetadataAsArray(array $volumesMetadata): array
    {
        $volumesAsArray = [];

        foreach ($volumesMetadata as $volumeMetadata) {
            $volumesAsArray[] = $volumeMetadata->toArray();
        }

        return $volumesAsArray;
    }

    private function getNtfsErrorDataAsArray(array $ntfsErrors): array
    {
        $ntfsErrorArray = [];

        foreach ($ntfsErrors as $ntfsError) {
            $ntfsErrorArray[] = [
                'volume' => $ntfsError['volume']->toArray(),
                'trans_ids' => $ntfsError['trans_ids']
            ];
        }

        return $ntfsErrorArray;
    }

    /**
     * @param ApplicationResult[] $applicationResults
     */
    private function formatApplicationResultsAsArray(array $applicationResults): array
    {
        $resultsArray = [];

        foreach ($applicationResults as $applicationResult) {
            $resultsArray[$applicationResult->getName()] = [
                'name' => $applicationResult->getName(),
                'running' => $applicationResult->isRunning(),
                'status' => $applicationResult->getStatus(),
                'complete' => $applicationResult->detectionCompleted()
            ];
        }

        return $resultsArray;
    }
}
