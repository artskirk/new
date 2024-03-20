<?php

namespace Datto\Asset\Agent\Job;

use Datto\Asset\Agent\Api\AgentTransferResult;
use Datto\Asset\Agent\Api\AgentTransferState;
use Datto\Resource\DateTimeService;
use Exception;

/**
 * Tracks the current status of a backup
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupJobStatus
{
    /** @var int */
    private $jobID;

    /** @var AgentTransferState */
    private $transferState;

    /** @var AgentTransferResult */
    private $transferResult;

    /** @var int */
    private $sent;

    /** @var int */
    private $total;

    /** @var int */
    private $lastUpdateTime;

    /** @var int */
    private $transferStart;

    /** @var int */
    private $errorCode;

    /** @var string[] */
    private $volumeBackupTypes;

    /** @var string[] */
    private $volumeGuids;

    /** @var BackupJobVolumeDetails[] */
    private $volumeDetails;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var string */
    private $errorCodeStr;

    /** @var string */
    private $errorMsg;

    /**
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(
        DateTimeService $dateTimeService = null
    ) {
        $this->jobID = 0;
        $this->transferState = AgentTransferState::NONE();
        $this->transferResult = AgentTransferResult::NONE();
        $this->sent = 0;
        $this->total = 0;
        $this->lastUpdateTime = 0;
        $this->transferStart = 0;
        $this->errorCode = 0;
        $this->volumeBackupTypes = [];
        $this->volumeGuids = [];
        $this->volumeDetails = [];
        $this->errorCodeStr = 'SUCCESS';
        $this->errorMsg = '';

        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
    }

    /**
     * @return AgentTransferState
     */
    public function getTransferState(): AgentTransferState
    {
        return $this->transferState;
    }

    /**
     * @param AgentTransferState $transferState
     */
    public function setTransferState(AgentTransferState $transferState): void
    {
        $this->transferState = $transferState;
    }

    /**
     * @return AgentTransferResult
     */
    public function getTransferResult(): AgentTransferResult
    {
        return $this->transferResult;
    }

    /**
     * @param AgentTransferResult $transferResult
     */
    public function setTransferResult(AgentTransferResult $transferResult): void
    {
        $this->transferResult = $transferResult;
    }

    /**
     * @return int Amount transferred
     */
    public function getSent(): int
    {
        return $this->sent;
    }

    /**
     * @return int
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function getLastUpdateTime(): int
    {
        return $this->lastUpdateTime;
    }

    /**
     * Updates the amount sent and total based on the given values.
     * Updates the time to the current time.
     * Updates the transfer start time if it this is the first time updating the amounts sent.
     *
     * @param int $sent
     * @param int $total
     */
    public function updateAmountsSent(int $sent, int $total): void
    {
        $this->sent = $sent;
        $this->total = $total;
        $this->lastUpdateTime = $this->dateTimeService->getTime();

        if ($this->transferStart === 0) {
            $this->transferStart = $this->lastUpdateTime;
        }
    }

    /**
     * @return int Completion percentage
     */
    public function getPercentComplete(): int
    {
        $percent = 0;
        if ($this->total > 0) {
            $percent = floor(($this->sent / $this->total) * 100);
        }
        return $percent;
    }

    /**
     * @return int Error code
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * @return string Error code string
     */
    public function getErrorCodeStr(): string
    {
        return $this->errorCodeStr;
    }

    /**
     * @return string Error message
     */
    public function getErrorMsg(): string
    {
        return $this->errorMsg;
    }

    /**
     * Set error data. This represents detailed error information for troubleshooting.
     *
     * @param int $errorCode
     * @param string $errorCodeStr
     * @param string $errorMsg
     */
    public function setErrorData(int $errorCode, string $errorCodeStr, string $errorMsg): void
    {
        $this->errorCode = $errorCode;
        $this->errorCodeStr = $errorCodeStr;
        $this->errorMsg = $errorMsg;
    }

    /**
     * Retrieve the backup status in a associative array
     *
     * @return array
     */
    public function getBackupStatusAsArray(): array
    {
        $data['time'] = $this->lastUpdateTime;
        $data['sent'] = $this->sent;
        $data['total'] = $this->total;
        $data['transferStart'] = $this->transferStart;

        return $data;
    }

    /**
     * @return bool True if the backup transfer is complete
     */
    public function isBackupComplete()
    {
        return $this->transferResult !== AgentTransferResult::NONE();
    }

    /**
     * @return string[]
     */
    public function getVolumeBackupTypes(): array
    {
        return $this->volumeBackupTypes;
    }

    /**
     * @param string[] $volumeBackupTypes
     */
    public function setVolumeBackupTypes(array $volumeBackupTypes): void
    {
        $this->volumeBackupTypes = $volumeBackupTypes;
    }

    public function getVolumeDetails(string $volumeGuid): BackupJobVolumeDetails
    {
        if (!array_key_exists($volumeGuid, $this->volumeDetails)) {
            throw new Exception("Unable to find $volumeGuid in volume details");
        }

        return $this->volumeDetails[$volumeGuid];
    }

    /**
     * Assumes that the provided volumeDetails object already has a volume guid set on it
     *
     * @param BackupJobVolumeDetails $volumeDetails
     */
    public function setVolumeDetails(BackupJobVolumeDetails $volumeDetails): void
    {
        if (!is_null($volumeDetails->getVolumeGuid())) {
            $volumeGuid = $volumeDetails->getVolumeGuid();
            $this->volumeDetails[$volumeGuid] = $volumeDetails;
        }
    }

    /**
     * @return string[]
     */
    public function getVolumeGuids(): array
    {
        return $this->volumeGuids;
    }

    /**
     * @param string[] $volumeGuids
     */
    public function setVolumeGuids(array $volumeGuids): void
    {
        $this->volumeGuids = $volumeGuids;
    }

    public function getJobID(): string
    {
        return $this->jobID;
    }

    public function setJobID(string $jobID): void
    {
        $this->jobID = $jobID;
    }
}
