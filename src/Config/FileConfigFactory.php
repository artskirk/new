<?php

namespace Datto\Config;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;

/**
 * Factory class to create a FileConfig instance
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class FileConfigFactory
{
    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem|null $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem(new ProcessFactory());
    }

    /**
     * Create a new FileConfig instance
     *
     * @param string $directory the base directory for the file config instance
     * @return FileConfig
     */
    public function create(string $directory): FileConfig
    {
        return new FileConfig($directory, $this->filesystem);
    }
}
