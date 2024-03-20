<?php
namespace Datto\Filesystem;

use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;

/**
 * Provides methods to manage "transparent" mounts. Such mounts are used to
 * expose symlinks pointing to block devices as regular files. This allows to
 * create NFS/Samba shares for encrypted systems.
 *
 * NOTE: If StitchfsMount is used to target the same files (usually *.datto),
 *       then there's no need to use TransparentMount as stitchfs will present
 *       the "virtual" files as regular files to application layer anyway so it
 *       would be wasteful to use stitchfs mounts on top of transmnt mount.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
class TransparentMount extends AbstractFuseOverlayMount
{
    /**
     * {@inheritdoc}
     */
    protected function getFuseBinary(): string
    {
        return 'transmnt';
    }

    /**
     * Creates a transparent mount point over existing directory path.
     *
     * A transparent mounts are ones where symlinks to block devices are exposed
     * as regular files. This is used to enable creating NFS/Samba shares for
     * encrypted systems.
     *
     * @param string $sourcePath
     * @param array $options
     *  Other FUSE options to pass from the caller.
     *
     * @return string
     *  The path to "transparent" mount point.
     */
    public function createTransparentMount(string $sourcePath, array $options = []): string
    {
        return $this->createFuseMount($sourcePath, $options);
    }

    /**
     * Removes a transparent mount point.
     *
     * A backwards compatible wrapper to AbstractFuseOverlayMount::removeFuseMount()
     *
     * @param string $sourcePath
     * @param bool $terminatePid
     */
    public function removeTransparentMount(string $sourcePath, bool $terminatePid = false)
    {
        $this->removeFuseMount($sourcePath, $terminatePid);
    }

    /**
     * Gets whether trasparent mount exists.
     *
     * A backwards compatible wrapper to AbstractFuseOverlayMount::hasFuseMount()
     *
     * @param string $sourcePath
     *
     * @return bool
     */
    public function hasTransparentMount(string $sourcePath)
    {
        return $this->hasFuseMount($sourcePath);
    }

    /**
     * Translates source path to transparent path.
     *
     * A backwards compatible wrapper to AbstractFuseOverlayMount::getFuseMountPath()
     *
     * @param string $sourcePath
     *
     * @return string
     */
    public function getTransparentPath(string $sourcePath): string
    {
        return $this->getFuseMountPath($sourcePath);
    }
}
