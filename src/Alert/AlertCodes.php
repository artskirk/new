<?php

namespace Datto\Alert;

/**
 * Class AlertCodes contains the log codes that are designated to generate alerts and various functions to deal with
 * parsing those log codes for meaning.
 */
class AlertCodes
{
    const URGENT_WARNING = 128;     // Produces a yellow warning banner and sends a warning email
    const REMOTELOG = 64;
    const SPECIAL   = 16;
    const AUDIT     = 8;
    const CRITICAL  = 4;            // Sends a critical error email
    const WARNING   = 2;            // Sends a warning email
    const ERROR     = 1;            // Produces a red error banner
    const PREVENT_24_HOUR_BACKUP_CHECK_CODES = ['BKP0013', 'BAK0013', 'BAK0015', 'BAK0027', 'BAK0028', 'BAK0029',
        'BAK0030', 'BAK0032', 'BKP0615', 'BAK0615', 'BAK3000', 'BKP3031', 'BKP3201', 'BKP7201', 'BAK7201',
        'BAK4260'];

    /**
     * @var array Array of all alerted codes plus their flags
     */
    public static $codes = array(
        //                  Aud  | Crit | Warn |  Err |
        "AGT0900" => 2, //     - |    - |    x |    - |    Agent pairing failed, will re-attempt
        "AGT0915" => 4, //     - |    x |    - |    - |    Agent pairing failed giving up
        "AGT1500" => 5, //     - |    x |    - |    x |    Agent removal failed, giving up.
        "VSV0003" => 2, //     - |    - |    x |    - |    Successfully included volume by name: $volumeIdentifier
        "VSV0005" => 2, //     - |    - |    x |    - |    Successfully excluded volume by guid: $volumeGuid
        "VSV0007" => 2, //     - |    - |    x |    - |    Successfully excluded volume by name: $volumeIdentifier
        "VSV0012" => 128, //                               Included volumes were not backed up because they were unavailable during the process.
        "AGT2000" => 1, //     - |    - |    - |    x |    This device is out of service and cannot (add new agents|register new agents|repair agents) anymore
        "BIL0002" => 2, //     - |    - |    x |    - |    Device out of service, emails are suppressed until device is back in service
        "BKP0013" => 5, //     - |    x |    - |    x |    Cannot connect to the host - aborting snapshot
        "BAK0013" => 5, //     - |    x |    - |    x |    Cannot connect to the host - aborting snapshot
        "BAK0015" => 5, //     - |    x |    - |    x |    This agent requires a reboot of the protected system. Please reboot the protected system.
        "BKP0022" => 3, //     - |    - |    x |    x |    Agent Driver is not loaded. Please restart the system to load the driver
        "BAK0130" => 128, //                               Backup of VMX configuration failed, because a hypervisor connection for this virtual guest could not be found.
        "BAK0022" => 3, //     - |    - |    x |    x |    Agent Driver is not loaded. Please restart the system to load the driver
        "BAK0027" => 5, //     - |    x |    - |    x |    DWA has changed to a different type.
        "BAK0028" => 5, //     - |    x |    - |    x |    ShadowSnap agent has changed to a different type.
        "BAK0029" => 5, //     - |    x |    - |    x |    DLA has changed to a different type.
        "BAK0030" => 5, //     - |    x |    - |    x |    Mac has changed to a different type.
        "BAK0032" => 5, //     - |    x |    - |    x |    Unknown agent has changed to a different type.
        "BAK0309" => 1, //     - |    - |    - |    x |    The agent failed to report the backup status. The protected system may have rebooted during backup.
        "BKP0203" => 1, //     - |    - |    - |    x |    VSS Export error occurred mid-transfer - falling back to STC
        "BAK0203" => 1, //     - |    - |    - |    x |    VSS Export error occurred mid-transfer - falling back to [STC|DBD]
        "BKP0100" => 16,//                                 Snapshot Requested
        "BAK0100" => 16,//                                 Snapshot Requested
        "BAK0101" => 5, //     - |    x |    - |    x |    Agentless backup error.
        "BKP0300" => 16,//                                 Snap was successful, returning true from takeSnap
        "BKP0510" => 1, //     - |    - |    - |    x |    No Volumes have been selected for backup
        "BAK0510" => 1, //     - |    - |    - |    x |    No Volumes have been selected for backup
        "BKP0613" => 16,//     - |    - |    - |    - |    Clearing needsBackup file
        "BKP0615" => 5, //     - |    x |    - |    x |    Backup skipped due to not enough free space.
        "BAK0615" => 5, //     - |    x |    - |    x |    Backup skipped due to not enough free space.
        "BKP1450" => 3, //     - |    - |    x |    x |    RollBack - The backup job was unable to complete. One or more volumes may have failed to be backed up
        "BAK1450" => 3, //     - |    - |    x |    x |    RollBack - The backup job was unable to complete. One or more volumes may have failed to be backed up
        "BKP1615" => 3, //     - |    - |    x |    x |    Disk is nearly full (only <space> GB left), backup may fail...
        "BAK1615" => 3, //     - |    - |    x |    x |    Disk is nearly full (only <space> GB left), backup may fail...
        "BKP1650" => 3, //     - |    - |    x |    x |    Backup failed as Backup job was unable to be assigned
        "BKP2013" => 3, //     - |    - |    x |    x |    Unable to connect to the host.
        "BKP2126" => 16,//                                 NAF snapshot failure
        "BKP2040" => 3, //     - |    - |    x |    x |    Unable to create disk images for backup of ".$hostVol['mountpoints']
        "BAK2040" => 3, //     - |    - |    x |    x |    Error creating or repairing partition for image file: <file> Exception: <exception>
        "BKP2202" => 3, //     - |    - |    x |    x |    Backup failed as job ( $thisJob ) was unable to be assigned
        "BAK2202" => 3, //     - |    - |    x |    x |    Backup attempt failed as job was unable to be assigned
        "BKP2212" => 3, //     - |    - |    x |    x |    Backup failed as job ( $thisJob ) was unable to be assigned + <agent auto-repair status>
        "BAK2212" => 3, //     - |    - |    x |    x |    Backup attempt failed as job was unable to be assigned
        "BAK3000" => 5, //     - |    x |    - |    x |    Critical backup failure: <error> Backup attempt _ of _
        "BKP3031" => 4, //     - |    x |    - |    - |    Backup is hung and cannot be stopped
        "BKP3201" => 5, //     - |    x |    - |    x |    $engine Export error occurred mid-transfer
        "BKP3300" => 16,//                                 Snap was successful, returning true from takeSnap
        "BAK3300" => 16,//                                 Snapshot was successful
        "BKP4000" => 5, //     - |    - |    x |    x |    Backup wasn't taken in over 24 hours.
        "BKP4010" => 5, //     - |    - |    x |    x |    Backup wasn't taken in over 24 hours. - Backup stuck in queue
        "BKP4020" => 5, //     - |    - |    x |    x |    Backup wasn't taken in over 24 hours. - Inhibit all cron file exists
        "BKP4030" => 5, //     - |    - |    x |    x |    Backup wasn't taken in over 24 hours. - Concurrent backups set to 0
        "BAK4201" => 16,//                                 Backup operation started
        "BAK4202" => 16,//                                 Backup operation completed
        "BKP7201" => 5, //     - |    x |    - |    x |    Critical backup failure
        "BAK7201" => 5, //     - |    x |    - |    x |    Critical backup failure
        "BTF0002" => 3, //     - |    - |    x |    x |    Esx connection with name: ( $connectionName ) not found.
        "DEV1002" => 8, //                                 Cron Functions have been inhibited for over 48 hours.  Please contact tech support.
        "RET1920" => 1, //     - |    - |    - |    x |    Retention has been running for 3 hours.  Please contact Support.
        "SCN0831" => 16,//                                 VM Screenshot Successful
        "SCN0832" => 16,//                                 VM Screenshot Failed
        "VER0510" => 16,//                                 OCR Result: Timed out while waiting for the OS to boot.
        "VER0511" => 16,//                                 OCR Result: A blank screen detected.
        "VER0512" => 16,//                                 OCR Result: VM screenshot TIMED OUT and FAILED to boot correctly.
        "VER0513" => 16,//                                 OCR Result: Blue screen of death detected.
        "VER0119" => 3, //     - |    - |    x |    x |    Screenshot verification failed. IDE storage controllers support 4 volumes maximum, but your backup has more than 4 volumes. Please select a different default storage controller on the 'Configure System Settings' page.
        "SCR0909" => 4, //     - |    x |    - |    - |    Error!  Agent: $agent has not taken a successful screenshot in over $hours hours.  Please contact tech support.
        "SNP0100" => 16,//                                 Snapshot Requested
        "SNP0126" => 16,//                                 NAF snapshot failure
        "ZFS3985" => 3, //     - |    - |    x |    x |    File-System could not properly remount... Please contact Support immediately and submit a ticket
        "ZFS3987" => 3, //     - |    - |    x |    x |    The ZFS File-System for $hostname is not mounted... Cannot proceed with backup.  Please contact Support immediately and submit a ticket
        "BAK3985" => 3, //     - |    - |    x |    x |    ZFS dataset is not mounted, cannot proceed with backup
        "ENC1001" => 8, //     x |    - |    - |    - |    Encrypted agent added
        "ENC1005" => 8, //     x |    - |    - |    - |    Passphrase entry failed
        "ENC1007" => 8, //     x |    - |    - |    - |    Passphrase entry succeeded
        "ENC1008" => 8, //     x |    - |    - |    - |    Passphrase entry failed - key removal
        "ENC1009" => 8, //     x |    - |    - |    - |    Passphrase entry succeeded - key removal
        "ENC1010" => 8, //     x |    - |    - |    - |    Tech access revoked
        "ENC1011" => 8, //     x |    - |    - |    - |    Tech access revoked
        "ENC1012" => 5, //     - |    x |    - |    x |    Backup failed because this encrypted agent's master key is unavailable. Please log into the device web interface and decrypt the agent.
        "ENC1014" => 5, //     - |    x |    - |    x |    Failed to check iSCSI portal registration status. The agent may not be properly paired with the device.
        "ENC1016" => 68,//     x |    - |    - |    - |    Inconsistency between stashed VMK and VMK on disk
        "MAL0001" => 128, //                               The snapshot taken at <timestamp> shows signs of ransomware.
        "BAK4260" => 4, //     - |    x |    - |    - |    Take snapshot failed!
        "SNS0003" => 1, //     - |    - |    - |    x |    Share $name does not exist
        "WAG0008" => 1, //     - |    - |    - |    x |    Windows agent backup error
        "MAG0008" => 1, //     - |    - |    - |    x |    Mac agent backup error
        "LAG0008" => 1, //     - |    - |    - |    x |    Linux agent backup error
        "ENS0013" => 5, //     - |    X |    - |    x |    Failed to copy external share.
        "ENS0027" => 3, //     - |    - |    x |    x |    Failed to copy external share - partial transfer error
        "ENS0033" => 1, //     - |    - |    - |    x |    Failed to copy file during external share backup
        "ENS0039" => 1, //     - |    - |    - |    x |    NasGuard rsync failed because the sync command that follows rsync failed.  Please retry.
        "ENS0050" => 1, //     - |    - |    - |    x |    Unable to mount external share using previously saved parameters
    );

    /**
     * @var string[] A list of verification-specific codes (alerts that occur after/during screenshotting).
     */
    public static $verificationCodes = [
        "SCN0832",
        "VER0510",
        "VER0511",
        "VER0512",
        "VER0513",
        "VER0605",
        "VER0606",
        "VER0607",
    ];

    /** Prefix values and their categories
     * @var array
     */
    public static $prefix = array(
        "AGT" => "Agent",
        "BAK" => "Backup",
        "BKP" => "Backup",// pre backup-refactor
        "DEV" => "Device",
        "CLO" => "Cloud",
        "CNF" => "ConfigBackup",
        "ENC" => "Encryption",
        "ENS" => "External Network Access Share",
        "FLS" => "Filesystem",
        "HIR" => "Hardware Independent Restore",
        "ISC" => "iSCSI",
        "LIC" => "License",
        "LOP" => "Loop Interface",
        "NAS" => "Network Access Share",
        "PKG" => "Package",
        "RET" => "Retention",
        "RLL" => "Backup",
        "SBK" => "Backup",
        "SCN" => "Screenshot",
        "SCR" => "Screenshot",
        "SNP" => "Snapshot",
        "SNS" => "SnapNAS Share",
        "SPM" => "Mail",
        "SSP" => "ShadowSnap",
        "SYN" => "SpeedSync",
        "VMX" => "Mail",
        "TST" => "Test",
        "VER" => "Screenshot",
        "VRT" => "Virtualization",
        "ZFS" => "ZFS"
    );

    /**
     * @return int[] An array of all alert codes, keyed by their corresponding log code.
     */
    public static function getCodes()
    {
        return self::$codes;
    }

    /**
     * @param string $code
     *
     * @return bool whether or not the log code represents an audit-level alert
     */
    public static function checkAudit($code)
    {
        return self::checkCode($code, self::AUDIT);
    }

    /**
     * @param string $code
     *
     * @return bool whether or not the log code represents a critical-level alert
     */
    public static function checkCritical($code)
    {
        return self::checkCode($code, self::CRITICAL);
    }

    /**
     * @param string $code
     *
     * @return bool whether or not the log code represents a warning-level alert
     */
    public static function checkWarning($code)
    {
        return self::checkCode($code, self::WARNING);
    }

    /**
     * @param string $code
     *
     * @return bool whether or not the log code represents an urgent warning-level alert
     */
    public static function checkUrgentWarning($code)
    {
        return self::checkCode($code, self::URGENT_WARNING);
    }

    /**
     * @param string $code
     *
     * @return bool whether or not the log code represents an error-level alert
     */
    public static function checkError($code)
    {
        return self::checkCode($code, self::ERROR);
    }

    /**
     * @param string $code
     * @return bool whether or not the log code represents a special-level alert (which means it has its own log)
     */
    public static function checkSpecial($code)
    {
        return self::checkCode($code, self::SPECIAL);
    }

    /**
     * @param string $code
     * @return bool whether or not the log code represents a verification-time alert
     */
    public static function isVerificationCode(string $code) : bool
    {
        return in_array($code, self::$verificationCodes, true);
    }

    /**
     * Check the log code and return its corresponding alert code integer value
     * @param string $code
     *
     * @return int the log code's integer value, or 0 it isn't an alert code
     */
    public static function check($code)
    {
        if (array_key_exists($code, self::$codes)) {
            return self::$codes[$code];
        }
        return 0;
    }

    /**
     * @param string $code
     *
     * @return int The severity of the log code; corresponds to AlertCodes class constants
     */
    public static function getSeverity($code)
    {
        if (self::checkCritical($code) || self::checkWarning($code) || self::checkError($code)) {
            return self::check($code);
        }
        return 0;
    }

    /**
     * @param string $code
     *
     * @return string The log code's prefix
     */
    public static function getPrefix($code)
    {
        return substr($code, 0, 3);
    }

    /**
     * Get the log code's alert category.
     *
     * @param string $code
     *
     * @return string|null The code's alert category, or null if it is not associated with an alert category.
     */
    public static function getCategory($code)
    {
        $codePrefix = self::getPrefix($code);
        if (!isset(self::$prefix[$codePrefix])) {
            return null;
        }
        return self::$prefix[$codePrefix];
    }

    /**
     * Check to see if this log code is an alert code.
     *
     * @param string $code
     * @param int $level
     *
     * @return bool
     */
    private static function checkCode($code, $level)
    {
        if (array_key_exists($code, self::$codes)) {
            return (self::$codes[$code] & $level) === $level;
        }
        return false;
    }
}
