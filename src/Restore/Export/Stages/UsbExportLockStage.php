<?php

namespace Datto\Restore\Export\Stages;

use Datto\Restore\Export\Usb\UsbLock;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Manages the lock used to block concurrent USB exports.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UsbExportLockStage extends AbstractStage
{
    /** @var UsbLock */
    private $lock;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        UsbLock $lock,
        Filesystem $filesystem
    ) {
        $this->lock = $lock;
        $this->filesystem = $filesystem;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $wait = false;
        $isLocked = $this->lock->exclusive($wait);
        if (!$isLocked) {
            throw new Exception('Unable to acquire lock');
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        $lockPath = $this->lock->path();
        $this->lock->unlock();
        // Destroy the object to release the file handle.
        $this->lock = null;
        $this->filesystem->unlink($lockPath);
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $this->cleanup();
    }
}
