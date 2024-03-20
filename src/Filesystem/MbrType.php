<?php

namespace Datto\Filesystem;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Represents MBR Type
 *
 * @author Jason Lodice <jlodice@datto.com>
 *
 * @method static MbrType PRIMARY()
 * @method static MbrType EXTENDED()
 */
class MbrType extends AbstractEnumeration
{
    const PRIMARY = 'p';
    const EXTENDED = 'e';
}
