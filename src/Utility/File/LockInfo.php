<?php

namespace Datto\Utility\File;

/**
 * Class that contains all of the paths used by locks, and their associated default timeouts.  This should be the
 * single source of truth for the information about where the lock files are stored!
 *
 * @author Mark Blakley <mblakley@datto.com>
 *
 * @codeCoverageIgnore
 */
class LockInfo
{
    const CONFIGFS_LOCK_PATH = '/dev/shm/configFS.lock';
    const CONFIGFS_LOCK_WAIT_TIMEOUT = 300;

    const CERTIFICATE_HELPER_LOCK_PATH = '/dev/shm/certificateHelper.lock';
    const CERTIFICATE_HELPER_LOCK_WAIT_TIMEOUT = 60;

    const CERTIFICATE_UPDATE_SERVICE_LOCK_PATH = '/dev/shm/certificateUpdateService.lock';

    const COMMAND_LOCK_DIR = '/dev/shm/snapctl/';

    const SAMBA_LOCK_FILE = '/dev/shm/sambaFileRestoreStage.lock';

    const RESTORE_LOCK_FILE = '/dev/shm/safeFileRestoreStage.lock';
}
