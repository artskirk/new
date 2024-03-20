<?php

namespace Datto\System\Migration;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Represents the type of migration
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 *
 * @method static MigrationType ZPOOL_REPLACE()
 * @method static MigrationType DEVICE()
 */
class MigrationType extends AbstractEnumeration
{
    const ZPOOL_REPLACE = 'zpool';
    const DEVICE = 'device';
}
