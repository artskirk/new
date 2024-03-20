<?php

namespace Datto\Samba;

use Datto\Common\Utility\Filesystem;

/**
 * Factory for text version of samba password  objects.
 *
 * @author John Fury Christ<jchrist@datto.com>
 */
class SmbpasswdFileRepositoryFactory
{
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
     * Create a repository to access a user-specified shadow file.
     *
     * @param string $filename
     * @return SmbpasswdFileRepository
     */
    public function createFileRepository(string $filename): SmbpasswdFileRepository
    {
        return new SmbpasswdFileRepository($filename, 0, '', $this->filesystem);
    }
}
