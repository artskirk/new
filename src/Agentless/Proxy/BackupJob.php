<?php

namespace Datto\Agentless\Proxy;

/**
 * Encapsulates the data needed to perform a block level backup of a VMs underlying disk image files.
 * It has all the data needed by mercuryftp to perform the execution.
 *
 * @author Mario Rial <mrial@datto.com>
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class BackupJob
{
    public const BACKUP_TYPE_INCREMENTAL = "incremental";
    public const BACKUP_TYPE_FULL = "full";
    public const BACKUP_TYPE_FULL_NO_CBT = "full_no_cbt";
    public const DIFF_MERGE = "differential";

    /** @var string */
    private $source;

    /** @var string */
    private $destination;

    /** @var string Changed Id used to retrieve the current changed areas. (just for debugging) */
    private $changeId;

    /** @var string ChangeId that will be written to changeIdFile in case of success */
    private $newChangeId;

    /** @var string */
    private $changeIdFile;

    /** @var array Changed Areas, digested for mercuryftp.
     * Example:
     * [
     *   [
     *     "source_offset": 95843,
     *     "destination_offset": 39489,
     *     "length": 948908
     *   ],
     *   [
     *     "sourceOffset": 94543,
     *     "destinationOffset": 3489,
     *     "length": 94908
     *   ],
     * ]
     */
    private $changedAreas;

    /** @var int sum of all the changed areas lengths */
    private $totalBytes;

    /** @var string just for progress reporting */
    private $backupJobId;

    /** @var string */
    private $backupType;

    /** @var bool */
    private $diffMerge;

    /**
     * PartitionBackupJobInfo constructor.
     * @param string $source
     * @param string $destination
     * @param string $changeId
     * @param array $changedAreas
     * @param string $newChangeId
     * @param string $changeIdFile
     * @param int $totalBytes
     * @param string $backupJobId
     * @param string $backupType
     * @param bool $diffMerge
     */
    public function __construct(
        string $source,
        string $destination,
        string $changeId,
        array $changedAreas,
        string $newChangeId,
        string $changeIdFile,
        int $totalBytes,
        string $backupJobId,
        string $backupType,
        bool $diffMerge
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->changeId = $changeId;
        $this->newChangeId = $newChangeId;
        $this->changeIdFile = $changeIdFile;
        $this->changedAreas = $changedAreas;
        $this->totalBytes = $totalBytes;
        $this->backupJobId = $backupJobId;
        $this->backupType = $backupType;
        $this->diffMerge = $diffMerge;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @return string
     */
    public function getDestination(): string
    {
        return $this->destination;
    }

    /**
     * @return string
     */
    public function getChangeId(): string
    {
        return $this->changeId;
    }

    /**
     * @return array
     */
    public function getChangedAreas(): array
    {
        return $this->changedAreas;
    }

    /**
     * @return int
     */
    public function getTotalBytes(): int
    {
        return $this->totalBytes;
    }

    /**
     * @return string
     */
    public function getBackupJobId(): string
    {
        return $this->backupJobId;
    }

    /**
     * @return string
     */
    public function getNewChangeId(): string
    {
        return $this->newChangeId;
    }

    /**
     * @return string
     */
    public function getChangeIdFile(): string
    {
        return $this->changeIdFile;
    }

    /**
     * @return string
     */
    public function getBackupType(): string
    {
        return $this->backupType;
    }

    /**
     * @return bool
     */
    public function isDiffMerge(): bool
    {
        return $this->diffMerge;
    }
}
