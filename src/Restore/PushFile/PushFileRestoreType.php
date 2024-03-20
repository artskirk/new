<?php

namespace Datto\Restore\PushFile;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @author Ryan Mack <rmack@datto.com>
 *
 * @method static PushFileRestoreType IN_PLACE()
 * @method static PushFileRestoreType AS_ARCHIVE()
 */
final class PushFileRestoreType extends AbstractEnumeration
{
    const IN_PLACE = 'in-place';
    const AS_ARCHIVE = 'as-archive';
}
