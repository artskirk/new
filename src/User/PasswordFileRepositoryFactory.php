<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;

/**
 * Factory for PasswordFileRepository objects.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class PasswordFileRepositoryFactory
{
    const LINUX_PASSWD_FILE = '/etc/passwd';

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
     * Create a repository to access the Linux system "/etc/passwd" file.
     *
     * @return PasswordFileRepository
     */
    public function createSystemRepository(): PasswordFileRepository
    {
        return new PasswordFileRepository(self::LINUX_PASSWD_FILE, 0644, 'root', $this->filesystem);
    }

    /**
     * Create a repository to access a user-specified passwd file.
     *
     * @param $filename
     * @return PasswordFileRepository
     */
    public function createFileRepository($filename): PasswordFileRepository
    {
        return new PasswordFileRepository($filename, 0, '', $this->filesystem);
    }
}
