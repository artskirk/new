<?php

namespace Datto\Asset\RecoveryPoint;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * Reason for the destruction of an offsite snapshot.
 *
 * @author Chad Kosie <ckosie@datto.com>
 *
 * @method static DestroySnapshotReason MANUAL()
 * @method static DestroySnapshotReason RETENTION()
 * @method static DestroySnapshotReason MIGRATION()
 */
class DestroySnapshotReason extends AbstractEnumeration
{
    const MANUAL = 'manual';
    const RETENTION = 'retention';
    const MIGRATION = 'device-migration';
}
