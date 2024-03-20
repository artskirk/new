<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;

/**
 * Factory for WebAccessFileRepository objects.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class WebAccessFileRepositoryFactory
{
    const WEBACCESS_FILE = '/datto/config/local/webaccess';

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Create a repository to access the Linux system "/etc/shadow" file.
     *
     * @return WebAccessFileRepository
     */
    public function createSystemRepository(): WebAccessFileRepository
    {
        return new WebAccessFileRepository(self::WEBACCESS_FILE, 0640, 'root', $this->filesystem);
    }

    /**
     * Create a repository to access a user-specified shadow file.
     *
     * @param $filename
     * @return WebAccessFileRepository
     */
    public function createFileRepository($filename): WebAccessFileRepository
    {
        return new WebAccessFileRepository($filename, 0, '', $this->filesystem);
    }
}
