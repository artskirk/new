<?php

namespace Datto\Security;

use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use RuntimeException;

/**
 * A class to use for files that hold secrets.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class SecretFile
{
    // Note: /run/shm is a tmpfs filesystem and subject to disk persistence in swap
    // A temporary ramfs partition is an alternative which is not swapped,
    // but overkill in the context of a PHP app, since PHP memory is itself swappable.
    const SECRET_FILE_PATH = '/run/shm/';
    const BACKGROUND_TASK_START_TIMEOUT_SECONDS = 2;

    /** @var string|null */
    private $filename;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        Filesystem $filesystem = null,
        DateTimeService $dateTimeService = null,
        Sleep $sleep = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->sleep = $sleep ?: new Sleep();
        $this->filename = null;
    }

    public function __destruct()
    {
        $this->shred();
    }

    /**
     * Returns the full path of the secret file
     *
     * @return string|null Full path of the secret file, or null if there isn't currently a file on the filesystem
     * associated with this SecretFile object.
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Determine whether or not there is currently a file on the filesystem associated with this SecretFile.
     *
     * @return bool whether or not there is a file on the filesystem associated with this SecretFile.
     */
    public function fileExists(): bool
    {
        return $this->filename !== null;
    }

    /**
     * Save the file contents to a new secret randomly named file, shredding any secrets that were previously
     * associated with this SecretFile object.
     *
     * @param mixed $fileContents Contents to write to the secret file
     * @return bool whether or not the save operation was successful
     */
    public function save(string $fileContents): bool
    {
        if ($this->createNewRandomFile()) {
            if ($this->filesystem->filePutContents($this->filename, $fileContents)) {
                return true;
            } elseif (!$this->shred()) {
                // We failed to write the secret to the random file; we want to null out the filename in the unlikely
                // event that the above shred operation fails so as not to leave this object in an inconsistent state.
                $this->filename = null;
            }
        }
        return false;
    }

    /**
     * Destroy the secret file
     *
     * @return bool whether or not the secret file was successfully shredded
     */
    public function shred(): bool
    {
        if ($this->fileExists() && $this->filesystem->shred($this->filename)) {
            $this->filename = null;
            return true;
        }
        return false;
    }

    /*
     * Waits until the secret file is removed or throws an error on timeout. This can be used
     *  when starting up a screen that should read the file and then delete it.
     */
    public function waitUntilSecretFileRemoved()
    {
        $startTime = $this->dateTimeService->getTime();
        while ($this->filesystem->exists($this->filename)) {
            $elapsedTime = $this->dateTimeService->getTime() - $startTime;
            if ($elapsedTime > self::BACKGROUND_TASK_START_TIMEOUT_SECONDS) {
                throw new RuntimeException("Timeout reached while waiting for secret file to be removed.");
            }
            $this->sleep->sleep(1);
        }
    }

    /**
     * Create a file with a unique random name and set this SecretFile's filename to the path of that file if it is
     * successfully created. If there is a previously associated file to this object, it will be shredded upon the
     * successful creation of the new file.
     *
     * @return bool true if a random file was successfully created, false otherwise, including the case in which there
     * is currently a file associated with this SecretFile object and there is a problem deleting it.
     */
    private function createNewRandomFile(): bool
    {
        if ($this->fileExists() && !$this->shred()) {
            return false;
        }
        $newFile = $this->filesystem->tempName(static::SECRET_FILE_PATH, "");
        if ($newFile) {
            $this->filename = $newFile;
            return true;
        }
        return false;
    }
}
