<?php

namespace Datto\Utility\Cloud;

use Exception;
use Throwable;

class RemoteDestroyException extends Exception
{
    // These error codes are pulled from speedsync's RemoteDestroyCodes class and ShellOutputModel.
    // Note that this is not a complete list of error codes that speedsync remote destroy can return. Error codes from
    // shell commands that are run are returned directly by speedsync in some cases.
    public const REMOTE_DESTROY_CODES = [
        10 => 'Unable to destroy dataset on offsite server.', // RemoteDestroyCodes::ZFS_DESTROY_FAILED
        11 => 'Unable to determine dataset status on offsite server.', // RemoteDestroyCodes::ZFS_READ_FAILED
        12 => 'Unable to move dataset to recycle bin on offsite server.', // RemoteDestroyCodes::ZFS_RENAME_FAILED
        13 => 'Unable to report dataset deletion to recycle bin on offsite server. Rolling back deletion.', // RemoteDestroyCodes::RECYCLE_BIN_PURGE_FAILURE
        14 => 'Dry run to destroy dataset on offsite server failed. Please remove any offsite restores and try again.', // RemoteDestroyCodes::ZFS_DRY_RUN_FAILED
        15 => 'Unable to destroy dataset on offsite server, a hold is present.', // RemoteDestroyCodes::ZFS_HOLD_PRESENT
        16 => 'Unable to destroy dataset on offsite server, a zfs pause is in effect.', // RemoteDestroyCodes::ZFS_PAUSED
        17 => 'Unable to destroy dataset on offsite server, snapshots are still in use from another process.', // RemoteDestroyCodes::ZFS_BUSY
        18 => 'Unable to destroy dataset on offsite server, partial receive of offsite data cannot be aborted.', // RemoteDestroyCodes::ZFS_ABORT_PARTIAL_FAILED
        19 => 'Unable to destroy snapshot on offsite server, it is a critical connecting point.', // RemoteDestroyCodes::CONNECTING_POINT
        20 => 'Snapshot has already been destroyed offsite.', // RemoteDestroyCodes::SNAPSHOT_ALREADY_DESTROYED
        21 => 'Unable to destroy snapshot on offsite server. Please remove any offsite restores and try again.', // RemoteDestroyCodes::ZFS_DEPENDENT_CLONES
        22 => 'Cannot find target to destroy on offsite server.', // RemoteDestroyCodes::TARGET_FAILURE
        23 => 'Unable to destroy, offsite sync is disabled.', // RemoteDestroyCodes::SYNC_DISABLED
        24 => 'Unable to remove vector to offsite server.',  // RemoteDestroyCodes::VECTOR_REMOVAL_FAILURE
        124 => 'Timed out trying to destroy dataset on offsite server.', // timeout error
        254 => '(254) Unable to connect to offsite server to destroy dataset.', // ssh remote error
        255 => '(255) Unable to connect to offsite server to destroy dataset.' // ssh connection error
    ];

    public const USER_RESOLVABLE_ERRORS = [
        14, // user can remove offsite restore to fix
        21 // user can remove offsite restore to fix
    ];

    public function __construct($code = 0, Throwable $previous = null)
    {
        $message = self::REMOTE_DESTROY_CODES[$code] ?? "Unknown error ($code) occurred when attempting to destroy data offsite.";

        parent::__construct($message, $code, $previous);
    }
}
