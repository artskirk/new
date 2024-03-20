<?php
/**
 * SystemdRunningStatus.php
 * @author Mark Blakley <mblakley@datto.com>
 */

namespace Datto\Utility\Systemd;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * An enumeration of systemd running statuses
 *
 * @method static SystemdRunningStatus INITIALIZING()
 * @method static SystemdRunningStatus STARTING()
 * @method static SystemdRunningStatus RUNNING()
 * @method static SystemdRunningStatus DEGRADED()
 * @method static SystemdRunningStatus MAINTENANCE()
 * @method static SystemdRunningStatus STOPPING()
 * @method static SystemdRunningStatus OFFLINE()
 * @method static SystemdRunningStatus UNKNOWN()
 */
class SystemdRunningStatus extends AbstractEnumeration
{
    const INITIALIZING = "initializing";
    const STARTING = "starting";
    const RUNNING = "running";
    const DEGRADED = "degraded";
    const MAINTENANCE = "maintenance";
    const STOPPING = "stopping";
    const OFFLINE = "offline";
    const UNKNOWN = "unknown";
}
