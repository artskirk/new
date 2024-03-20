<?php

namespace Datto\Utility\File;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;

/**
 * Factory class for creating locks, so that they can be injected into services.
 *
 * @author Peter Geer <pgeer@datto.com>
 *
 * @codeCoverageIgnore
 */
class LockFactory
{
    /** @var Lock[] */
    private static $locks = [];

    /** @var Filesystem */
    private $filesystem;

    /** @var Sleep */
    private $sleep;

    /**
     * @param Filesystem|null $filesystem
     * @param Sleep|null $sleep
     */
    public function __construct(
        Filesystem $filesystem = null,
        Sleep $sleep = null
    ) {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * Creates a new Lock object.
     * The lock will automatically be released when the returned lock object
     * goes out of scope.
     *
     * @param string $path Lock file path
     * @return Lock
     */
    public function create(string $path): Lock
    {
        return new Lock($path, $this->filesystem, $this->sleep);
    }

    /**
     * Get a lock object which persists until the process ends.
     * This lock is not released when the returned Lock object goes out of scope.
     * It is stored in a static cache and is only unlocked if either "unlock()"
     * is called or the process ends.
     *
     * @param string $path Lock file path
     * @return Lock
     */
    public function getProcessScopedLock(string $path): Lock
    {
        // Reuse locks. If the reference to a lock object is lost, the lock will be released.
        // That can happen before the php process ends its execution.
        if (!isset(static::$locks[$path])) {
            static::$locks[$path] = new Lock($path, $this->filesystem, $this->sleep);
        }

        return static::$locks[$path];
    }
}
