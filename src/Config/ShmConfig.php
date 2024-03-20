<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Access files stored in shared memory
 */
class ShmConfig extends FileConfig
{
    const BASE_SHM_PATH = '/dev/shm';

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        parent::__construct(self::BASE_SHM_PATH, $filesystem ?: new Filesystem(new ProcessFactory()));
    }
}
