<?php

namespace Datto\File;

/**
 * Wraps xattr functions
 *
 * @author Alexander Mechler <amechler@datto.com>
 */
class Xattr
{
    const ATTR_CIFS = 'system.cifs_acl';
    const ATTR_NTFS = 'system.ntfs_acl';

    /**
     * @param string $filename
     * @param string $attribute
     * @param int $flags
     * @return String|bool String of the attribute value if successful, false if unsuccessful.
     */
    public function get(string $filename, string $attribute, int $flags = 0)
    {
        return xattr_get($filename, $attribute, $flags);
    }

    /**
     * @param string $filename
     * @param string $attribute
     * @param string $value
     * @param int $flags
     * @return bool true if successful, false otherwise
     */
    public function set(string $filename, string $attribute, string $value, int $flags = 0) : bool
    {
        return xattr_set($filename, $attribute, $value, $flags);
    }

    /**
     * @param string $fromXattr
     * @param string $toXattr
     * @param string $sourcePath
     * @param string $destinationPath
     * @return bool true if successful, false otherwise.
     */
    public function copyAttribute(string $fromXattr, string $toXattr, string $sourcePath, string $destinationPath): bool
    {
        /** @psalm-suppress UndefinedConstant XATTR_ALL is defined by the xattr extension */
        $xattr = $this->get($sourcePath, $fromXattr, XATTR_ALL);
        if ($xattr) {
            /** @psalm-suppress UndefinedConstant XATTR_ALL is defined by the xattr extension */
            return $this->set($destinationPath, $toXattr, (string)$xattr, XATTR_ALL);
        }

        return false;
    }
}
