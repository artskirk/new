<?php

namespace Datto\Resource;

/**
 * Resource class that wraps xattr php methods.
 *
 * This was not included in Filesystem in system-common as it requires a debian package install and we did
 * not want to impose that constraint on other clients of system-common.
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class FilesystemAttributes
{
    /**
     * Gets the value of an extended attribute of a file
     *
     * @param string $path
     * @param string $attributeName
     *
     * @return string
     */
    public function getExtendedAttribute(string $path, string $attributeName): string
    {
        return xattr_get($path, $attributeName);
    }
}
