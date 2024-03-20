<?php

namespace Datto\Util;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @method static OsFamily LINUX()
 * @method static OsFamily WINDOWS()
 * @method static OsFamily MAC()
 * @method static OsFamily UNKNOWN()
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class OsFamily extends AbstractEnumeration
{
    const LINUX = "linux";
    const WINDOWS = "windows";
    const MAC = "mac";
    const UNKNOWN = "unknown";
}
