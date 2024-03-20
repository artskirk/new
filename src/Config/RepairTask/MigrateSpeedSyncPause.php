<?php

namespace Datto\Config\RepairTask;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Config\DeviceConfig;
use Datto\Config\LocalConfig;
use Datto\Core\Configuration\ConfigRepairTaskInterface;

/**
 * Class MigrateSpeedSyncPause
 *
 * With version 6.12+ of SpeedSync, the siris-os-2 code is now in charge of the pause/resume logic. This
 * task migrates those configuration values from /datto/config/local to /datto/config and removes an unnecessary
 * key.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class MigrateSpeedSyncPause implements ConfigRepairTaskInterface
{
    const LEGACY_DELAY_KEY = 'delay';
    const LEGACY_DELAY_START_KEY = 'delayStart';
    const LEGACY_SPEEDSYNC_DISABLED_KEY = 'sync/rsyncSendDisabled';
    const LEGACY_INDEFINITE_DELAY = 87600 * 60 * 60; // 10 years in seconds

    private LocalConfig $localConfig;
    private DeviceConfig $deviceConfig;

    public function __construct(
        LocalConfig $localConfig,
        DeviceConfig $deviceConfig
    ) {
        $this->localConfig = $localConfig;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Migrate previously-internal SpeedSync configuration, as well as legacy device local configuration keys
     * relating to SpeedSync/Offsite Pause functionality.
     *
     * @return true if the configuration file has changed
     */
    public function run(): bool
    {
        $modified = $this->migrateCloudPause();
        $modified |= $this->migrateLocalPause();

        return $modified;
    }

    /**
     * Prior to SpeedSync 6.12, the checkin process would reach right in and touch this file, and we would query
     * speedsync for the pause state. Now that pause is owned by the device, we need to migrate this file to a
     * place that the device can manage.
     *
     * @return bool True if any config was modified
     */
    private function migrateCloudPause(): bool
    {
        // Bypass any additional logic if the config doesn't actually exist
        if (!$this->deviceConfig->has(self::LEGACY_SPEEDSYNC_DISABLED_KEY)) {
            return false;
        }

        // If the flag is non-zero, touch the `cloudPause` key. Otherwise, no cloud-initiated delay was in place
        $legacyDisabled = $this->deviceConfig->get(self::LEGACY_SPEEDSYNC_DISABLED_KEY, 0);
        if ($legacyDisabled) {
            $this->deviceConfig->touch(SpeedSyncMaintenanceService::CLOUD_PAUSE_KEY);
        }

        // Remove the legacy key
        $this->deviceConfig->clear(self::LEGACY_SPEEDSYNC_DISABLED_KEY);

        return true;
    }

    private function migrateLocalPause(): bool
    {
        // If we don't have either the `delay` or `delayStart` keys, there's nothing to do, so return early
        if (!$this->localConfig->has(self::LEGACY_DELAY_KEY) &&
            !$this->localConfig->has(self::LEGACY_DELAY_START_KEY)) {
            return false;
        }

        // The old mechanism used to write a `delay` key containing the unix epoch time when the local pause would
        // expire. It also used to write a `delayStart` key containing the epoch time when the local pause went into
        // effect. The only usage of this key was to determine if the delay was indefinite (10 years in seconds).

        // It also had a mechanism where if it detected that speedsync itself was paused, and for a reason not the
        // delay file, it would write a '1' to that file, which was an indication that we had told deviceweb we
        // were paused. That's handled elsewhere (rsyncSendDisabled) above, so we don't need to worry about migrating
        // that case anymore.

        $resumeTime = (int)$this->localConfig->get(self::LEGACY_DELAY_KEY, 0);
        $delayStart = (int)$this->localConfig->get(self::LEGACY_DELAY_START_KEY, 0);

        // If the resume time is set to 1, that means the device was tracking a pause from device-web (e.g. the
        // rsyncSendDisabled) flag migrated above. In that case, we don't want to migrate this file forward.
        if ($resumeTime !== 1) {
            // The old method of tracking "indefinite" delays was to store two keys, now we just use -1
            if (($resumeTime - $delayStart) >= self::LEGACY_INDEFINITE_DELAY) {
                $resumeTime = -1;
            }
            $this->deviceConfig->set(SpeedSyncMaintenanceService::OFFSITE_PAUSE_KEY, $resumeTime);
        }

        $this->localConfig->clear(self::LEGACY_DELAY_KEY);
        $this->localConfig->clear(self::LEGACY_DELAY_START_KEY);
        return true;
    }
}
