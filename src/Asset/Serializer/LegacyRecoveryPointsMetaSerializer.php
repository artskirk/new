<?php

namespace Datto\Asset\Serializer;

use Datto\Asset\Agent\VolumeMetadata;
use Datto\Asset\ApplicationResult;
use Datto\Asset\RecoveryPoint\RecoveryPoint;
use Datto\Asset\RecoveryPoint\RecoveryPoints;
use Datto\Asset\VerificationScreenshotResult;
use Datto\Asset\VerificationScriptsResults;
use Datto\Asset\RansomwareResults;
use Datto\Filesystem\FilesystemCheckResult;

/**
 * Serializes the .recoveryPointsMeta file containing backup, verification, and
 * filesystem integrity information for each snapshot.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class LegacyRecoveryPointsMetaSerializer implements Serializer
{
    /** @var VerificationScriptsResultsSerializer */
    private $verificationScriptsResultsSerializer;

    /**
     * LegacyRecoveryPointsMetaSerializer constructor.
     *
     * @param VerificationScriptsResultsSerializer|null $verificationScriptsResultsSerializer
     */
    public function __construct(VerificationScriptsResultsSerializer $verificationScriptsResultsSerializer = null)
    {
        $this->verificationScriptsResultsSerializer =
            $verificationScriptsResultsSerializer ?: new VerificationScriptsResultsSerializer();
    }

    /**
     * @param RecoveryPoints $recoveryPoints
     * @return array
     */
    public function serialize($recoveryPoints)
    {
        $recoveryPointMetasArray = $this->toArray($recoveryPoints);

        return serialize($recoveryPointMetasArray);
    }

    /**
     * @param mixed $fileArray
     * @return RecoveryPoints
     */
    public function unserialize($fileArray)
    {
        //[BCDR-17582] The unserialize line was separated from pulling the recoveryPointsMeta from the fileArray to prevent a PHP seg fault
        $recoveryPointsMeta = @$fileArray['recoveryPointsMeta'];
        $unserialized = unserialize($recoveryPointsMeta, ['allowed_classes' => false]);
        $keyName = @$fileArray['keyName'];

        if (is_array($unserialized)) {
            $recoveryPoints = $this->fromArray($keyName, $unserialized);
        } else {
            $recoveryPoints = new RecoveryPoints();
        }

        return $recoveryPoints;
    }

    /**
     * Creates an array containing all recovery point meta information.
     *
     * @param RecoveryPoints $recoveryPoints
     * @return array
     */
    public function toArray(RecoveryPoints $recoveryPoints): array
    {
        $recoveryPointMetas = $recoveryPoints->getBoth();
        $recoveryPointMetasArray = [];
        /**  @var RecoveryPoint $recoveryPoint */
        foreach ($recoveryPointMetas as $epoch => $recoveryPoint) {
            if ($recoveryPoint === null) {
                $recoveryPointArray = [
                    'epoch' => $epoch,
                    'ransomwareResults' => null,
                    'verificationScriptsResults' => null,
                    'missingVolumes' => []
                ];
            } else {
                $recoveryPointArray = $this->singleToArray($recoveryPoint);
            }
            $recoveryPointMetasArray[$epoch] = $recoveryPointArray;
        }

        return $recoveryPointMetasArray;
    }

    /**
     * Creates a RecoveryPoints object containing all recovery point meta information from the array.
     *
     * @param string $keyName
     * @param array $unserialized
     * @return RecoveryPoints
     */
    public function fromArray(string $keyName, array $unserialized): RecoveryPoints
    {
        $recoveryPoints = new RecoveryPoints();

        foreach ($unserialized as $epoch => $recoveryPointArray) {
            if (trim($epoch) !== '') {
                $recoveryPoint = $this->singleFromArray($keyName, (int)$epoch, $recoveryPointArray);
                $recoveryPoints->add($recoveryPoint);
            }
        }

        return $recoveryPoints;
    }

    /**
     * Serialize a single RecoveryPoint.
     *
     * @param RecoveryPoint $recoveryPoint
     * @return array
     */
    public function singleToArray(RecoveryPoint $recoveryPoint)
    {
        $ransomwareResults = $this->serializeRansomwareResults($recoveryPoint);
        $verificationScriptsResults = $this->serializeVerificationScriptResults($recoveryPoint);
        $filesystemCheckResults = $this->serializeFilesystemCheckResults(
            $recoveryPoint->getFilesystemCheckResults()
        );

        $recoveryPointArray = [
            'epoch' => $recoveryPoint->getEpoch(),
            'ransomwareResults' => $ransomwareResults,
            'verificationScriptsResults' => $verificationScriptsResults,
            'volumeBackupTypes' => $recoveryPoint->getVolumeBackupTypes(),
            'missingVolumes' => $this->serializeMissingVolumes($recoveryPoint->getMissingVolumes()),
            'backupEngineUsed' => $recoveryPoint->getEngineUsed(),
            'backupEngineConfigured' => $recoveryPoint->getEngineConfigured(),
            'deletionTime' => $recoveryPoint->getDeletionTime(),
            'deletionReason' => $recoveryPoint->getDeletionReason(),
            'filesystemCheckResults' => $filesystemCheckResults,
            'applicationResults' => $this->serializeApplicationResults($recoveryPoint->getApplicationResults()),
            'serviceResults' => $this->serializeApplicationResults($recoveryPoint->getServiceResults()),
            'wasBackupForced' => $recoveryPoint->wasBackupForced(),
            'osUpdatePending' => $recoveryPoint->wasOsUpdatePending(),
            'screenshotResult' => $this->serializeVerificationScreenshotResult($recoveryPoint)
        ];

        return $recoveryPointArray;
    }

    /**
     * Unserialize a single RecoveryPoint.
     *
     * @param string $keyName
     * @param int $epoch
     * @param array $recoveryPointArray
     * @return RecoveryPoint
     */
    public function singleFromArray(string $keyName, int $epoch, array $recoveryPointArray)
    {
        $ransomware = $this->unserializeRansomwareResults($recoveryPointArray['ransomwareResults']);
        $verificationScriptsResults =
            $this->unserializeVerificationScriptResults(
                $recoveryPointArray['verificationScriptsResults'] ?? null
            );
        $volumeBackupTypes = $recoveryPointArray['volumeBackupTypes'] ?? [];
        $backupEngineUsed = $recoveryPointArray['backupEngineUsed'] ?? null;
        $backupEngineConfigured = $recoveryPointArray['backupEngineConfigured'] ?? null;
        $deletionTime = $recoveryPointArray["deletionTime"] ?? null;
        $deletionReason = $recoveryPointArray["deletionReason"] ?? null;
        $wasBackupForced = $recoveryPointArray['wasBackupForced'] ?? null;
        $wasOsUpdatePending = $recoveryPointArray['osUpdatePending'] ?? null;

        return new RecoveryPoint(
            $epoch,
            $ransomware,
            $verificationScriptsResults,
            $volumeBackupTypes,
            $this->unserializeMissingVolumes($recoveryPointArray['missingVolumes'] ?? []),
            $backupEngineUsed,
            $backupEngineConfigured,
            $deletionTime,
            $deletionReason,
            $this->unserializeFilesystemCheckResults($recoveryPointArray['filesystemCheckResults'] ?? []),
            $this->unserializeApplicationResults($recoveryPointArray['applicationResults'] ?? []),
            $this->unserializeApplicationResults($recoveryPointArray['serviceResults'] ?? []),
            $this->unserializeVerificationScreenshotResult($recoveryPointArray['screenshotResult'] ?? null),
            $wasBackupForced,
            $wasOsUpdatePending
        );
    }

    /**
     * @param ApplicationResult[] $applicationResults
     * @return array
     */
    private function serializeApplicationResults(array $applicationResults): array
    {
        $applicationResultsArray = [];

        foreach ($applicationResults as $applicationResult) {
            $applicationResultsArray[] = [
                'name' => $applicationResult->getName(),
                'status' => $applicationResult->getStatus()
            ];
        }

        return $applicationResultsArray;
    }

    /**
     * @param array $applicationResultsArray
     * @return ApplicationResult[]
     */
    private function unserializeApplicationResults(array $applicationResultsArray): array
    {
        $applicationResults = [];

        foreach ($applicationResultsArray as $applicationResultArray) {
            if (!isset($applicationResultArray['name']) || !isset($applicationResultArray['status'])) {
                continue;
            }

            $applicationResults[] = new ApplicationResult(
                $applicationResultArray['name'],
                (int)$applicationResultArray['status']
            );
        }

        return $applicationResults;
    }

    /**
     * @param string[][] $missingVolumesArray
     * @return VolumeMetadata[]
     */
    private function unserializeMissingVolumes(array $missingVolumesArray = [])
    {
        $missingVolumes = [];

        foreach ($missingVolumesArray as $missingVolumeArray) {
            $missingVolumes[] = new VolumeMetadata($missingVolumeArray['mountPoint'], $missingVolumeArray['guid']);
        }

        return $missingVolumes;
    }

    /**
     * @param VolumeMetadata[] $missingVolumes
     * @return array
     */
    private function serializeMissingVolumes(array $missingVolumes)
    {
        $missingVolumesArray = [];

        foreach ($missingVolumes as $missingVolume) {
            $missingVolumesArray[] = [
                'mountPoint' => $missingVolume->getMountpoint(),
                'guid' => $missingVolume->getGuid()
            ];
        }

        return $missingVolumesArray;
    }

    /**
     * @param array $filesystemCheckResultsArray
     * @return FilesystemCheckResult[]
     */
    private function unserializeFilesystemCheckResults(array $filesystemCheckResultsArray = []): array
    {
        $filesystemCheckResults = [];

        foreach ($filesystemCheckResultsArray as $filesystemCheckResultArray) {
            $resultCode = $filesystemCheckResultArray['resultCode'];
            $resultDetails = $filesystemCheckResultArray['resultDetails'] ?? null;
            $volumeGuid = $filesystemCheckResultArray['volumeGuid'] ?? null;
            $volumeMountPoint = $filesystemCheckResultArray['volumeMountPoint'] ?? null;

            $filesystemCheckResults[$volumeGuid] = new FilesystemCheckResult(
                $resultCode,
                new VolumeMetadata($volumeMountPoint, $volumeGuid),
                $resultDetails
            );
        }

        return $filesystemCheckResults;
    }

    /**
     * @param FilesystemCheckResult[] $filesystemCheckResults
     * @return array
     */
    private function serializeFilesystemCheckResults(array $filesystemCheckResults = []): array
    {
        $filesystemCheckResultsArray = [];

        foreach ($filesystemCheckResults as $filesystemCheckResult) {
            $volumeMetadata = $filesystemCheckResult->getVolumeMetadata();

            $filesystemCheckResultsArray[] = [
                'resultCode' => $filesystemCheckResult->getResultCode(),
                'resultDetails' => $filesystemCheckResult->getResultDetails(),
                'volumeGuid' => $volumeMetadata->getGuid(),
                'volumeMountPoint' => $volumeMetadata->getMountpoint()
            ];
        }

        return $filesystemCheckResultsArray;
    }

    /**
     * @param array
     *
     * @return RansomwareResults|null
     */
    private function unserializeRansomwareResults($ransomwareResultsArray): ?RansomwareResults
    {
        if ($ransomwareResultsArray === null) {
            $ransomwareResults = null;
        } else {
            $ransomwareResults = new RansomwareResults(
                $ransomwareResultsArray['agentKeyname'],
                $ransomwareResultsArray['snapshotEpoch'],
                $ransomwareResultsArray['hasRansomware'],
                $ransomwareResultsArray['hasException']
            );
        }
        return $ransomwareResults;
    }

    /**
     * Converts ransomware results to an array. If no ransomware results, return null.
     *
     * @param RecoveryPoint $recoveryPoint
     * @return string[]|null
     *
     * @psalm-suppress PossiblyNullReference
     */
    private function serializeRansomwareResults($recoveryPoint)
    {
        if ($recoveryPoint->getRansomwareResults() === null) {
            return null;
        }
        return [
            'agentKeyname' => $recoveryPoint->getRansomwareResults()->getAgentKeyname(),
            'snapshotEpoch' => $recoveryPoint->getRansomwareResults()->getSnapshotEpoch(),
            'hasRansomware' => $recoveryPoint->getRansomwareResults()->hasRansomware(),
            'hasException' => $recoveryPoint->getRansomwareResults()->hasException()
        ];
    }

    /**
     * Converts verification scripts results to array. If no ransomware results, return null.
     *
     * @param RecoveryPoint $recoveryPoint
     * @return string[]|null
     */
    private function serializeVerificationScriptResults($recoveryPoint)
    {
        if ($recoveryPoint->getVerificationScriptsResults() === null) {
            return null;
        }

        return $this->verificationScriptsResultsSerializer->serialize($recoveryPoint->getVerificationScriptsResults());
    }

    /**
     * Converts verificationscriptsresults array into verificationscriptsresults object. If none, return null
     *
     * @param $verificationScriptsResultsArray
     * @return VerificationScriptsResults
     */
    private function unserializeVerificationScriptResults($verificationScriptsResultsArray)
    {
        return $this->verificationScriptsResultsSerializer->unserialize($verificationScriptsResultsArray);
    }

    /**
     * Converts verification screenshot result to an array. If no result, return null.
     *
     * @param RecoveryPoint $recoveryPoint
     * @return string[]|null
     */
    private function serializeVerificationScreenshotResult(RecoveryPoint $recoveryPoint)
    {
        $verificationScreenshotResult = $recoveryPoint->getVerificationScreenshotResult();

        if ($verificationScreenshotResult === null) {
            return null;
        }

        return [
            'hasScreenshot' => $verificationScreenshotResult->hasScreenshot(),
            'isOsUpdatePending' => $verificationScreenshotResult->isOsUpdatePending(),
            'failureAnalysis' => $verificationScreenshotResult->getFailureAnalysis()
        ];
    }

    /**
     * Converts verificationscreenshotresult array into an object. If none, return null
     *
     * @param array|null $verificationScreenshotResultArray
     * @return VerificationScreenshotResult|null
     */
    private function unserializeVerificationScreenshotResult($verificationScreenshotResultArray)
    {
        if ($verificationScreenshotResultArray === null) {
            return null;
        }

        return new VerificationScreenshotResult(
            $verificationScreenshotResultArray['hasScreenshot'],
            $verificationScreenshotResultArray['isOsUpdatePending'],
            $verificationScreenshotResultArray['failureAnalysis']
        );
    }
}
