<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Configuration\RemoteSettings;
use Datto\Log\RemoteLogSettings;
use Datto\Samba\SambaManager;
use Datto\System\WatchdogService;
use Datto\Util\Email\EmailService;
use Datto\Verification\VerificationService;
use Exception;

/**
 * API endpoint to query and change device settings.
 *
 * Important note:
 *   This is an API endpoint, and NOT the actual library class.
 *   Do NOT add actual functionality to this class. Use library
 *   classes (and functions, if you must) instead!
 *
 * How to call this API?
 *   Please refer to the /api/api.php file and/or the
 *   Datto\API\Server class for details on how to call this API.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class Settings
{
    const SECONDS_PER_DAY = 86400;
    const DEFAULT_CONCURRENT_BACKUPS = 2;
    const DEFAULT_CONCURRENT_BACKUPS_CLOUD_DEVICE = 10;

    private DeviceConfig $deviceConfig;
    private LocalConfig $localConfig;
    private WatchdogService $watchdogService;
    private RemoteSettings $remoteSettings;
    private RemoteLogSettings $remoteLogSettings;
    private EmailService $emailService;
    private SambaManager $sambaManager;
    private VerificationService $verificationService;

    public function __construct(
        DeviceConfig $deviceConfig,
        LocalConfig $localConfig,
        WatchdogService $watchdogService,
        RemoteSettings $remoteSettings,
        RemoteLogSettings $remoteLogSettings,
        EmailService $emailService,
        SambaManager $sambaManager,
        VerificationService $verificationService
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->localConfig = $localConfig;
        $this->watchdogService = $watchdogService;
        $this->remoteSettings = $remoteSettings;
        $this->remoteLogSettings = $remoteLogSettings;
        $this->emailService = $emailService;
        $this->sambaManager = $sambaManager;
        $this->verificationService = $verificationService;
    }

    /**
     * Returns the status of the advanced alerting flag (useAdvancedAlerting).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING_ADVANCED")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_READ")
     * @return bool True if the flag is set, false otherwise.
     */
    public function getAdvancedAlerting()
    {
        return $this->deviceConfig->has('useAdvancedAlerting');
    }

    /**
     * Set or clear the advanced alerting flag (useAdvancedAlerting)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING_ADVANCED")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @param bool $enabled Enable or disable the flag
     */
    public function setAdvancedAlerting($enabled): bool
    {
        if ($enabled) {
            $this->deviceConfig->set('useAdvancedAlerting', 1);
        } else {
            $this->deviceConfig->clear('useAdvancedAlerting');
        }

        return true;
    }

    /**
     * Returns the time offset to use for backups to avoid conflicts (backupOffset).
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_BACKUP_OFFSET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BACKUP_OFFSET_READ")
     * @return bool The backup offset value in minutes
     */
    public function getBackupOffset()
    {
        return $this->deviceConfig->get('backupOffset');
    }

    /**
     * Sets the time offset to use for backups (backupOffset)
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_BACKUP_OFFSET")
     * @Datto\App\Security\RequiresPermission("PERMISSION_BACKUP_OFFSET_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "offset" = @Symfony\Component\Validator\Constraints\Range(min=0, max=55)
     * })
     * @param int $offset The backup offset value in minutes
     * @return bool always true
     */
    public function setBackupOffset($offset)
    {
        $this->deviceConfig->set('backupOffset', (int) $offset);

        return true;
    }

    /**
     * Sets speed at which backups are synced offsite (txSpeed), and
     * reports this value to the cloud.
     *
     * FIXME Move this to v1/device/offsite
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @param int $speed Speed in KB/s
     * @return bool always true
     */
    public function setOffsiteSyncSpeed($speed)
    {
        $this->localConfig->set('txSpeed', $speed);
        $this->remoteSettings->setOffsiteSyncSpeed($speed);

        return true;
    }

    /**
     * Gets the speed at which backups are synced offsite.
     *
     * FIXME Move this to v1/device/offsite
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return int
     */
    public function getOffsiteSyncSpeed(): int
    {
        return (int)$this->localConfig->get('txSpeed');
    }

    /**
     * Gets the number of backups allowed to occur at the same time
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CONCURRENT_BACKUPS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CONCURRENT_BACKUPS_READ")
     * @return int number of concurrent backups allowed
     */
    public function getConcurrentBackup(): int
    {
        return (int)$this->deviceConfig->get('maxBackups', self::DEFAULT_CONCURRENT_BACKUPS);
    }

    /**
     * Sets the number of backups allowed to occur at the same time
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_CONCURRENT_BACKUPS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_CONCURRENT_BACKUPS_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "count" = @Symfony\Component\Validator\Constraints\Range(min=0, max=10)
     * })
     * @param int $count
     */
    public function setConcurrentBackup($count): void
    {
        $this->deviceConfig->setAllowingEmptyData('maxBackups', $count);
    }

    /**
     *
     * Gets the period in days after which a restore left mounted will trigger an alert, zero indicates "never".
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING_MOUNTED_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_READ")
     * @return int
     */
    public function getMountedRestoreAlert(): int
    {
        $period = $this->deviceConfig->get('defaultTP', 0);
        return (int)($period / self::SECONDS_PER_DAY);
    }

    /**
     * Sets an alert to display when a restore is left mounted for more than n days
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING_MOUNTED_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "period" = @Symfony\Component\Validator\Constraints\GreaterThan(
     *     value=0,
     *     message="period must be greater than 0"
     * )
     * })
     * @param int $period days to wait before alerting
     */
    public function setMountedRestoreAlert($period): void
    {
        $this->deviceConfig->set('defaultTP', $period * self::SECONDS_PER_DAY);
    }

    /**
     * Disables the alert feature for restores that have been left mounted too long
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING_MOUNTED_RESTORE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     */
    public function disableMountedRestoreAlert(): void
    {
        $this->deviceConfig->set('defaultTP', 0);
    }

    /**
     * Gets whether screenshots are enabled or disabled for the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_READ")
     * @return bool
     */
    public function getScreenshots(): bool
    {
        return $this->verificationService->isScreenshotsEnabled();
    }

    /**
     * Enables/disables screenshotting on the device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_VERIFICATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_VERIFICATION_WRITE")
     * @param bool $enabled whether screenshoting should be enabled
     */
    public function setScreenshots(bool $enabled): void
    {
        $this->verificationService->setScreenshotsEnabled($enabled);
    }

    /**
     * Get whether remote logging (in CEF format) is enabled
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_CEF_LOGGING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_CEF_LOGGING")
     * @return bool whether logging is enabled
     */
    public function getRemoteLogging(): bool
    {
        return $this->deviceConfig->has('cefActive');
    }

    /**
     * Set whether remote logging (in CEF format) is enabled
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_CEF_LOGGING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_CEF_LOGGING")
     * @param bool $enabled whether logging is enabled
     */
    public function setRemoteLogging($enabled): void
    {
        $this->deviceConfig->set('cefActive', $enabled);
    }

    /**
     * Get the addresses and ports of the device's CEF remote logging servers
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_CEF_LOGGING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_CEF_LOGGING")
     * @return array[] remote logging servers, formatted as an array of arrays with keys 'address' and 'port'
     */
    public function getRemoteLoggingServers(): array
    {
        return $this->remoteLogSettings->getServers();
    }

    /**
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_REMOTE_CEF_LOGGING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_REMOTE_CEF_LOGGING")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "servers" = @Symfony\Component\Validator\Constraints\All({
     *     @Symfony\Component\Validator\Constraints\Collection(fields={
     *       "address" = @Symfony\Component\Validator\Constraints\NotNull(),
     *       "port" = @Symfony\Component\Validator\Constraints\Range(
     *                  min=0,
     *                  max=65535,
     *                  minMessage="min message",
     *                  maxMessage="max message"
     *              )
     *     },
     *     allowMissingFields = false,
     *     allowExtraFields = false)
     *   })
     * })
     * @param array $servers
     */
    public function setRemoteLoggingServers(array $servers): void
    {
        $this->remoteLogSettings->updateServers($servers);
    }

    /**
     * Enables/disables the watchdog on the device
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_WATCHDOG")
     * @Datto\App\Security\RequiresPermission("PERMISSION_WATCHDOG")
     * @param bool $enabled whether the watchdog should be enabled
     */
    public function setWatchdog($enabled): void
    {
        if ($enabled) {
            $this->watchdogService->enable();
        } else {
            $this->watchdogService->disable();
        }
    }

    /**
     * Get Device ID
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_DEVICE_INFO")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     * @return int deviceID
     */
    public function getDeviceId(): int
    {
        return (int)$this->deviceConfig->get('deviceID');
    }

    /**
     * Set the device primary contact email
     *
     * FIXME This should be combined with v1/device/customize/alerts + v1/device/emails
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "address" = {
     *         @Symfony\Component\Validator\Constraints\Email()
     *     },
     * })
     * @param string $address
     * @return bool
     */
    public function setDevicePrimaryContactEmail(string $address): bool
    {
        $this->emailService->setDeviceAlertsEmail($address);
        return true;
    }

    /**
     * Get the device primary contact email
     *
     * FIXME This should be combined with v1/device/customize/alerts + v1/device/emails
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_READ")
     * @return string
     */
    public function getDevicePrimaryContactEmail(): string
    {
        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        return $contactInfoRecord->getEmail();
    }

    /**
     * Get the device primary contact email from the cloud
     *
     * FIXME This should be combined with v1/device/customize/alerts + v1/device/emails
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_ALERTING")
     * @Datto\App\Security\RequiresPermission("PERMISSION_ALERTING_WRITE")
     * @return string
     */
    public function pullDevicePrimaryContactEmailFromCloud(): string
    {
        return $this->emailService->pullDevicePrimaryContactEmailFromCloud();
    }

    /**
     * Set the pagination scheme for the protect page agent listing
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_USER_INTERFACE")
     * @Datto\App\Security\RequiresFeature("FEATURE_AGENTS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_AGENT_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "sortField" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Choice(choices = {"hostname", "ip", "osName", "errorString", "lastBackupEpoch", "localUsed"})
     *   },
     *   "agentsPerPage" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Choice(choices = {1, 5, 10, 15, 20, 99999999})
     *   },
     *   "direction" = {
     *     @Symfony\Component\Validator\Constraints\NotNull(),
     *     @Symfony\Component\Validator\Constraints\Choice(choices = {"asc", "desc"})
     *   }
     * })
     *
     * @param string $sortField The field to use for sorting
     * @param int $agentsPerPage The max number of agents per page
     * @param string $direction Ascending or Descending order
     * @return bool
     */
    public function setAgentsListPagination(string $sortField, int $agentsPerPage, string $direction, bool $showArchived): bool
    {
        $pagination = [
            'pagination_sort' => $sortField,
            'agent_count' => $agentsPerPage,
            'direction' => $direction,
            'show_archived' => $showArchived
        ];

        return $this->deviceConfig->set(DeviceConfig::KEY_PAGINATION_SETTINGS, json_encode($pagination));
    }

    /**
     * Gets the device's SMB protocol minimum version
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SERVICE_SAMBA")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_INFO")
     * @return int the minimum version, 1 or 2.
     */
    public function getSMBProtocolMinimumVersion(): int
    {
        return $this->sambaManager->getServerProtocolMinimumVersion();
    }

    /**
     * Set the device's SMB protocol minimum version
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SERVICE_SAMBA")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_SETTINGS_WRITE")
     * @param int $version the minimum version to set, 1 or 2.
     */
    public function setSMBProtocolMinimumVersion(int $version) : void
    {
        if ($this->sambaManager->setServerProtocolMinimumVersion($version)) {
            $this->sambaManager->sync();
            if ($version === 1) {
                $this->deviceConfig->touch(DeviceConfig::KEY_ENABLE_SMB_MINIMUM_VERSION_ONE);
            } else {
                $this->deviceConfig->clear(DeviceConfig::KEY_ENABLE_SMB_MINIMUM_VERSION_ONE);
            }
        }
    }

    /**
     * Set whether SMB signing is required
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_SERVICE_SAMBA")
     * @Datto\App\Security\RequiresPermission("PERMISSION_DEVICE_SETTINGS_WRITE")
     */
    public function updateSMBSigningRequired(bool $isRequired) : void
    {
        $this->sambaManager->updateSigningRequired($isRequired);
        if (!$this->sambaManager->sync()) {
            throw new Exception("Failed to update SMB signing required");
        }
        $this->deviceConfig->setRaw(DeviceConfig::KEY_SMB_SIGNING_REQUIRED, $isRequired ? "1" : "0");
    }
}
