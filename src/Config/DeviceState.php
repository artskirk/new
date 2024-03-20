<?php

namespace Datto\Config;

use Datto\Common\Utility\Filesystem;

/**
 * Access state information about the device.
 * Only state data should be stored/accessed here. For configuration, use DeviceConfig.php
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class DeviceState extends FileConfig
{
    const BASE_PATH = '/var/lib/datto/device';
    const SPEED_SYNC_ACTIVE = "speedSyncActive";
    const EVENT_QUEUE = "eventQueue";
    const SCREENSHOT_RESULTS_MIGRATED = 'screenshotResultsMigrated';
    const SAMBA_CONFIG_UPDATED = 'sambaConfigUpdated';
    const RETENTION_MAXIMUM_UPDATED = 'retentionMaximumUpdated';
    const DTC_VSS_WRITERS_UPDATED = 'dtcVssWritersUpdated';
    const ENV_FILE = 'device.env';
    const CONNECTIVITY_STATE = 'connectivityState';
    const RESUMABLE_BACKUP_STATE = 'resumableBackupState';
    const MERCURYFTP_RESTART_REQUESTED = 'mercuryftpRestartRequested';

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        parent::__construct(self::BASE_PATH, $filesystem);
    }
}
