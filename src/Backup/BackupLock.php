<?php

namespace Datto\Backup;

use Datto\Asset\Agent\Agent;
use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\File\Lock;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Utility\Filesystem;

/**
 * Responsible for acquiring, releasing, and updating asset's backup lock file.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class BackupLock
{
    /** @const string Format string for full snapshot lock file path */
    const LOCK_FILE_FORMAT = '/dev/shm/%s.backupLock';

    /** @var string */
    private $assetKeyName;

    /** @var Lock */
    private $lock;

    /** @var Filesystem */
    private $filesystem;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var bool */
    private $isLockedByMe;

    /**
     * @param string $assetKeyName
     * @param Lock|null $lock
     * @param Filesystem|null $filesystem
     * @param PosixHelper|null $posixHelper
     */
    public function __construct(
        string $assetKeyName,
        Lock $lock = null,
        Filesystem $filesystem = null,
        PosixHelper $posixHelper = null
    ) {
        $this->assetKeyName = $assetKeyName;
        $lockFile = sprintf(self::LOCK_FILE_FORMAT, $this->assetKeyName);
        $this->lock = $lock ?: new Lock($lockFile);
        $processFactory = new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($processFactory);
        $this->posixHelper = $posixHelper ?: new PosixHelper($processFactory);
        $this->isLockedByMe = false;
    }

    /**
     * Acquire the lock on the snapshot lock file.
     * Updates the snapshot lock file with the pid of the current process.
     *
     * @param int $waitSeconds The number of seconds to wait for the lock before giving up.  Defaults to 0.
     */
    public function acquire(int $waitSeconds = 0)
    {
        $lockFile = $this->lock->path();
        if (!$this->lock->exclusiveAllowWait($waitSeconds)) {
            throw new BackupException('Failed to open snapLock file ' . $lockFile . ' for writing/exclusive lock');
        }
        $this->isLockedByMe = true;
        $this->filesystem->filePutContents($lockFile, (string)$this->posixHelper->getCurrentProcessId());

        $this->setOriginalSnapLock();
    }

    /**
     * Release and remove the snapshot lock file.
     */
    public function release()
    {
        if ($this->isLockedByMe) {
            $lockFile = $this->lock->path();
            $this->lock->unlock();
            $this->isLockedByMe = false;

            if ($this->filesystem->exists($lockFile)) {
                $this->filesystem->unlink($lockFile);
            }

            $this->clearOriginalSnapLock();
        }
    }

    /**
     * Determine if the backup lock file is locked.
     * This can be used to determine if a backup is running.
     *
     * @return bool True if locked.
     */
    public function isLocked()
    {
        return $this->lock->isLocked();
    }

    /**
     * Set the original snaplock file.
     * This is used to signal to the web UI that a backup is running.
     * todo: update webUI to either look at new file location or check the status file directly
     */
    private function setOriginalSnapLock()
    {
        $originalLockFile = $this->getOriginalSnapLockFile();
        $this->filesystem->filePutContents($originalLockFile, (string)$this->posixHelper->getCurrentProcessId());
    }

    /**
     * Clear the original snaplock file.
     * todo: update webUI to either look at new file location or check the status file directly
     */
    private function clearOriginalSnapLock()
    {
        $originalLockFile = $this->getOriginalSnapLockFile();
        if ($this->filesystem->exists($originalLockFile)) {
            $this->filesystem->unlink($originalLockFile);
        }
    }

    /**
     * Get the full path to the original lock file
     *
     * @return string
     */
    private function getOriginalSnapLockFile(): string
    {
        $keyBase = Agent::KEYBASE;
        $originalLockFile = $keyBase . $this->assetKeyName . '.snapLock';
        return $originalLockFile;
    }
}
