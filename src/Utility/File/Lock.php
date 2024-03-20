<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Log\Formatter\AbstractFormatter;

/**
 * This class represents a lock file which implements atomic file locking
 * operations using "flock".  This class does not modify the content or delete
 * the lock file, but uses it only for file locking operations.
 *
 * IMPORTANT:
 * This class will create the lock file along with all directories in the
 * lock file path if they do not exist.  However, the process of creating the
 * directories is not atomic, and can fail if done concurrently from
 * multiple processes.  This can cause even a blocking lock to fail and return
 * false.  To prevent errors, it is recommended that the directories which
 * contain the lock file already exist prior to using this class.
 */
class Lock
{
    const DEFAULT_LOCK_WAIT_TIME = 60;

    /** @var string */
    private $path;

    /** @var resource */
    private $file;

    /** @var Filesystem */
    private $filesystem;

    /** @var Sleep */
    private $sleep;

    /**
     * @param string $path
     * @param Filesystem|null $filesystem
     * @param Sleep|null $sleep
     */
    public function __construct(
        string $path,
        Filesystem $filesystem = null,
        Sleep $sleep = null
    ) {
        $this->path = $this->normalizePath($path);
        $this->file = null;
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->sleep = $sleep ?: new Sleep();
    }

    public function __destruct()
    {
        if ($this->filesystem->isFileResource($this->file)) {
            @$this->filesystem->lock($this->file, LOCK_UN);
            @$this->filesystem->close($this->file);
        }
    }

    /**
     * Obtain a shared lock.
     * This will create the lock file and parent directories if necessary.
     * See the IMPORTANT note at the top of this class.
     *
     * @param bool $wait TRUE to wait (block) until the lock can be obtained.
     * @return bool TRUE if the lock was successfully obtained, or
     *    FALSE if the lock was not obtained, the lock file could not be
     *      created, or the path to the lock file could not be created.
     */
    public function shared(bool $wait = true): bool
    {
        $flags = $wait ? LOCK_SH : LOCK_SH | LOCK_NB;
        return $this->set($flags);
    }

    /**
     * Obtain an exclusive lock.
     * This will create the lock file and parent directories if necessary.
     * See the IMPORTANT note at the top of this class.
     *
     * @param bool $wait TRUE to wait (block) until the lock can be obtained.
     * @return bool TRUE if the lock was successfully obtained, or
     *    FALSE if the lock was not obtained, the lock file could not be
     *      created, or the path to the lock file could not be created.
     */
    public function exclusive(bool $wait = true): bool
    {
        $flags = $wait ? LOCK_EX : LOCK_EX | LOCK_NB;
        return $this->set($flags);
    }

    /**
     * Obtain an exclusive lock, waiting up to the provided "maximumWaitSeconds" seconds.
     *
     * See self::exclusive for more details.
     *
     * @param int $maximumWaitSeconds Maximum number of seconds to wait for the lock.
     * @return bool TRUE if the lock was successfully obtained, or
     *    FALSE if the lock was not obtained, the lock file could not be
     *      created, or the path to the lock file could not be created.
     */
    public function exclusiveAllowWait(int $maximumWaitSeconds)
    {
        return $this->lockAllowWait($maximumWaitSeconds, $isExclusive = true);
    }

    /**
     * Obtain an exclusive lock, waiting up to the provided "maximumWaitSeconds" seconds, and throwing LockException
     * if the lock cannot be obtained within the specified number of seconds.
     *
     * See self::exclusiveAllowWait for more details.
     *
     * @param int $maximumWaitSeconds Maximum number of seconds to wait for the lock.
     */
    public function assertExclusiveAllowWait(int $maximumWaitSeconds)
    {
        if (!$this->exclusiveAllowWait($maximumWaitSeconds)) {
            throw new LockException(
                "Could not acquire exclusive {$this->path} lock within $maximumWaitSeconds seconds"
            );
        }
    }

    /**
     * Obtain a shared lock, waiting up to the provided "maximumWaitSeconds" seconds.
     *
     * See self::shared for more details.
     *
     * @param int $maximumWaitSeconds Maximum number of seconds to wait for the lock.
     * @return bool TRUE if the lock was successfully obtained, or
     *    FALSE if the lock was not obtained, the lock file could not be
     *      created, or the path to the lock file could not be created.
     */
    public function sharedAllowWait(int $maximumWaitSeconds)
    {
        return $this->lockAllowWait($maximumWaitSeconds, $isExclusive = false);
    }

    /**
     * Obtain a shared lock, waiting up to the provided "maximumWaitSeconds" seconds, and throwing LockException
     * if the lock cannot be obtained within the specified number of seconds.
     *
     * See self::exclusiveAllowWait for more details.
     *
     * @param int $maximumWaitSeconds Maximum number of seconds to wait for the lock.
     */
    public function assertSharedAllowWait(int $maximumWaitSeconds)
    {
        if (!$this->sharedAllowWait($maximumWaitSeconds)) {
            throw new LockException(
                "Could not acquire shared {$this->path} lock within $maximumWaitSeconds seconds"
            );
        }
    }

    /**
     * Release a lock.
     */
    public function unlock()
    {
        if ($this->filesystem->isFileResource($this->file)) {
            @$this->filesystem->lock($this->file, LOCK_UN);
        }
    }

    /**
     * @return bool
     */
    public function isLocked(): bool
    {
        $file = @$this->filesystem->open($this->path, 'c');
        if (!$this->filesystem->isFileResource($file)) {
            return false;
        }

        $status = @$this->filesystem->lock($file, LOCK_SH | LOCK_NB);

        if ($status === false) {
            @$this->filesystem->close($file);
            return true;
        }

        if ($status === true) {
            @$this->filesystem->lock($file, LOCK_UN);
        }

        @$this->filesystem->close($file);
        return false;
    }

    /**
     * Get the path to the lock file.
     *
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param int $flags
     * @return bool
     */
    private function set(int $flags): bool
    {
        if (!$this->open()) {
            return false;
        }

        return @$this->filesystem->lock($this->file, $flags) === true;
    }

    /**
     * This method prevents the PHP interpreter issue when binary code is executed with
     * the eval() and halt_compiler statements. In that case it evaluates
     * the magic constant __FILE__ as: /path/to/file.php(2) 'eval()\'d code'.
     */
    private function normalizePath(string $path): string
    {
        if (strpos($path, AbstractFormatter::SPENCERCUBE_LINE) !== false) {
            $path = explode('(', $path)[0];
        }
        return $path;
    }

    /**
     * Open the lock file.
     * This will create the lock file and any directories in the path to the
     * lock file if they do not exist.
     * Warning:  This process of creating the directory is not autonomous, and
     * can fail if another process is attempting to do the same thing.
     * See the IMPORTANT note at the top of this class.
     * @return bool TRUE if successful, FALSE if the lock file could not be
     *      created, or the path to the lock file could not be created.
     */
    private function open(): bool
    {
        if ($this->filesystem->isFileResource($this->file)) {
            return true;
        }

        $this->file = @$this->filesystem->open($this->path, 'c');
        if ($this->filesystem->isFileResource($this->file)) {
            return true;
        }

        if (@$this->filesystem->mkdir(dirname($this->path), true, 0777)) {
            $this->file = @$this->filesystem->open($this->path, 'c');
            if ($this->filesystem->isFileResource($this->file)) {
                return true;
            }
        }

        $this->file = null;
        return false;
    }

    private function lockAllowWait(int $maximumWaitSeconds, bool $isExclusive)
    {
        $timeoutMicroseconds = $maximumWaitSeconds * 1000 * 1000;

        do {
            $locked = $isExclusive ? $this->exclusive(false) : $this->shared(false);
            if (!$locked) {
                $waitNow = rand(100, 5000);
                $this->sleep->usleep($waitNow);
                $timeoutMicroseconds -= $waitNow;
            }
        } while (!$locked && $timeoutMicroseconds > 0);

        return $locked;
    }
}
