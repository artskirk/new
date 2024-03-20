<?php

namespace Datto\Backup;

/**
 * The current state of a backup along with any contextual information.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class BackupStatus
{
    /** @var string */
    private $state;

    /** @var string */
    private $backupType;

    /** @var mixed[] */
    private $additional;

    /**
     * @param string $state
     * @param array $additional
     * @param string|null $backupType
     */
    public function __construct(
        string $state,
        array $additional = [],
        string $backupType = null
    ) {
        $this->state = $state;
        $this->additional = $additional;
        $this->backupType = $backupType;
    }

    /**
     * @see BackupStatusService constants for more details.
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @return mixed[]
     */
    public function getAdditional(): array
    {
        return $this->additional;
    }

    /**
     * @return string|null
     */
    public function getBackupType()
    {
        return $this->backupType;
    }
}
