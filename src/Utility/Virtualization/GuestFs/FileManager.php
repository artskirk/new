<?php

namespace Datto\Utility\Virtualization\GuestFs;

/**
 * Class: FileManager
 *
 * Provides file management APIs of libguestfs.
 */
class FileManager extends GuestFsHelper
{
    /**
     * Download file from the VM image to local filesystem.
     *
     * @param string $sourcePath
     * @param string $destinationPath
     */
    public function downloadFile(string $sourcePath, string $destinationPath)
    {
        $this->throwOnFalse(guestfs_download($this->getHandle(), $sourcePath, $destinationPath));
    }

    /**
     * Upload file from local filesystem into the guest image.
     *
     * @param string $sourcePath
     * @param string $destinationPath
     */
    public function uploadFile(string $sourcePath, string $destinationPath)
    {
        $this->throwOnFalse(guestfs_upload($this->getHandle(), $sourcePath, $destinationPath));
    }

    /**
     * Get filesystem stats using `statvfs` syscall.
     *
     * @link https://linux.die.net/man/2/statvfs
     * @note elements in this structure are not prefixed with `f_` as shown in
     *       the statvfs documentation linked above. For example, "Block Size"
     *       is retrievable as `$statvfs['bsize']` **not** `$statvfs['f_bsize']`
     *
     * @param string $path
     *
     * @return array
     */
    public function getVfsStat(string $path): array
    {
        return $this->throwOnFalse(guestfs_statvfs($this->getHandle(), $path));
    }

    /**
     * Creates a directory on the guest filesystem.
     *
     * @param string $path
     */
    public function mkDir(string $path)
    {
        $this->throwOnFalse(guestfs_mkdir($this->getHandle(), $path));
    }

    /**
     * Return a list of files in the guest fs matching given pattern.
     *
     * @param string $pattern
     *
     * @return array
     */
    public function glob(string $pattern): array
    {
        return $this->throwOnFalse(guestfs_glob_expand($this->getHandle(), $pattern));
    }
}
