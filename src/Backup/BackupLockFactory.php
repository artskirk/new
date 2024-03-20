<?php

namespace Datto\Backup;

use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Utility\File\Lock;

class BackupLockFactory
{
    /** @var FileSystem */
    private $fileSystem;

    /** @var PosixHelper */
    private $posixHelper;

    public function __construct(
        FileSystem $fileSystem = null,
        PosixHelper $posixHelper = null
    ) {
        $this->fileSystem = $fileSystem;
        $this->posixHelper = $posixHelper;
    }

    /**
     * Creates a new backup lock.
     */
    public function create(string $assetKeyName, Lock $lock = null) : BackupLock
    {
        return new BackupLock($assetKeyName, $lock, $this->fileSystem, $this->posixHelper);
    }
}
