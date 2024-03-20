<?php

namespace Datto\Backup;

use Datto\Config\JsonConfigRecord;

/**
 * Class for reading/writing information in the needs backup flag
 */
class BackupRequest extends JsonConfigRecord
{
    const NEEDS_BACKUP_FLAG = 'needsBackup';

    /** @var int */
    private $queuedTime;

    /** @var array */
    private $metadata;

    public function __construct(
        int $queuedTime = 0,
        array $metadata = []
    ) {
        $this->queuedTime = $queuedTime;
        $this->metadata = $metadata;
    }

    public function getKeyName(): string
    {
        return self::NEEDS_BACKUP_FLAG;
    }

    public function getQueuedTime(): int
    {
        return $this->queuedTime;
    }

    public function setQueuedTime(int $queuedTime)
    {
        $this->queuedTime = $queuedTime;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata)
    {
        $this->metadata = $metadata;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'queuedTime' => $this->getQueuedTime(),
            'metadata' => $this->getMetadata()
        ];
    }

    /**
     * @inheritdoc
     */
    protected function load(array $vals)
    {
        $this->setQueuedTime($vals['queuedTime']);
        $this->setMetadata($vals['metadata']);
    }
}
