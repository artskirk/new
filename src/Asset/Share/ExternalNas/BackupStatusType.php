<?php

namespace Datto\Asset\Share\ExternalNas;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Represents the status of an external NAS backup.
 *
 * @author Peter Geer <pgeer@datto.com>
 *
 * @method static BackupStatusType IDLE()
 * @method static BackupStatusType INITIALIZING()
 * @method static BackupStatusType IN_PROGRESS()
 * @method static BackupStatusType COPYING_ACLS()
 */
class BackupStatusType extends AbstractEnumeration
{
    const IDLE = 0;
    const INITIALIZING = 1;
    const IN_PROGRESS = 2;
    const COPYING_ACLS = 3;
}
