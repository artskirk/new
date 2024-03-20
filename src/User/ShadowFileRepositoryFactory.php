<?php

namespace Datto\User;

use Datto\Common\Utility\Filesystem;

/**
 * Factory for ShadowFileRepository objects.
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class ShadowFileRepositoryFactory
{
    const LINUX_SHADOW_FILE = '/etc/shadow';

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
     * @return ShadowFileRepository
     */
    public function createSystemRepository(): ShadowFileRepository
    {
        return new ShadowFileRepository(self::LINUX_SHADOW_FILE, 0640, 'shadow', $this->filesystem);
    }

    /**
     * Create a repository to access a user-specified shadow file.
     *
     * @param $filename
     * @return ShadowFileRepository
     */
    public function createFileRepository($filename): ShadowFileRepository
    {
        return new ShadowFileRepository($filename, 0, '', $this->filesystem);
    }
}
