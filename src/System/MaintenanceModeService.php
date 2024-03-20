<?php

namespace Datto\System;

use Datto\Cloud\JsonRpcClient;
use Datto\Feature\FeatureService;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Manages the inhibitAllCron flag (aka "maintenance mode") on the device.
 *
 * The purpose of this flag is to stop MOST (not all) device activity, including
 * backups and screenshots.
 *
 * The flag has the following states:
 *   * If the flag files exists, but does not have any content, it will be removed
 *     when "last modified time + 48 hours" is reached.
 *   * If the flag contains a timestamp, the timestamp will be treated as the end
 *     time for the flag, after which it will be removed.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class MaintenanceModeService
{
    const MAINTENANCE_MODE_FILE = '/datto/config/inhibitAllCron';
    const MAX_ENABLED_TIME_IN_SECONDS = 172800; // 48 hrs
    const DEFAULT_ENABLE_TIME_IN_HOURS = 6;
    const ENABLE_MAINTENANCE_ENDPOINT = 'v1/device/maintenance/enable';
    const DISABLE_MAINTENANCE_ENDPOINT = 'v1/device/maintenance/disable';
    const MAINTENANCE_STATUS_ENDPOINT = 'v1/device/maintenance/status';

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $timeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Collector */
    private $collector;

    /** @var FeatureService */
    private $featureService;

    /** @var JsonRpcClient */
    private $deviceWebClient;

    public function __construct(
        Filesystem $filesystem,
        DateTimeService $timeService,
        DeviceLoggerInterface $logger,
        Collector $collector,
        FeatureService $featureService,
        JsonRpcClient $deviceWebClient
    ) {
        $this->filesystem = $filesystem;
        $this->timeService = $timeService;
        $this->logger = $logger;
        $this->collector = $collector;
        $this->featureService = $featureService;
        $this->deviceWebClient = $deviceWebClient;
    }

    /**
     * Enable maintenance mode for a certain number of hours.
     *
     * @param int $enableForHours
     * @param string|null $user Name of the user enabling maintenance mode. Used only for logging/auditing purposes.
     */
    public function enable(int $enableForHours, string $user = null)
    {
        $enableForSeconds = $enableForHours * 60 * 60;
        $this->enableForSeconds($enableForSeconds, $user);
    }

    /**
     * Enable maintenance mode for a certain number of seconds.
     *
     * @param int $enableForSeconds
     * @param string|null $user Name of the user enabling maintenance mode. Used only for logging/auditing purposes.
     */
    public function enableForSeconds(int $enableForSeconds, string $user = null)
    {
        if ($enableForSeconds <= 0) {
            throw new Exception('Invalid maintenance time value.');
        }

        $endTime = $this->timeService->getTime() + $enableForSeconds;
        $this->enableUntilTime($endTime, $user);
    }

    /**
     * Enable maintenance mode until the specified time.
     *
     * @param int $endTime Unix timestamp of the end time.
     * @param string|null $user Name of the user enabling maintenance mode. Used only for logging/auditing purposes.
     * @param bool $localOnly true if you want to skip enabling cloud maintenance and only set the keyfile.
     */
    public function enableUntilTime(int $endTime, string $user = null, bool $localOnly = false)
    {
        if (!$localOnly && $this->featureService->isSupported(FeatureService::FEATURE_CLOUD_MANAGED_MAINTENANCE)) {
            $this->enableMaintenanceViaCloud($endTime, $user);
        } else {
            $this->enableMaintenanceViaLocal($endTime, $user);
        }
    }

    /**
     * Disable maintenance mode if it was enabled. This will disable cloud maintenance
     * and remove the inhibitAllCron flag.
     *
     * @param string|null $user Name of the user disabling maintenance mode. Used only for logging/auditing purposes.
     * @param bool $localOnly true if you want to skip disabling cloud maintenance and only set the keyfile.
     */
    public function disable(string $user = null, bool $localOnly = false)
    {
        if (!$localOnly && $this->featureService->isSupported(FeatureService::FEATURE_CLOUD_MANAGED_MAINTENANCE)) {
            $this->disableMaintenanceViaCloud($user);
        } else {
            $this->disableMaintenanceViaLocal($user);
        }
    }

    /**
     * Checks whether maintenance mode is enabled, and disables it if
     * the end time (as per the flag contents) has been reached.
     *
     * Note:
     *    This method must be called once per MINUTE to ensure that
     *    isEnabled() and getEndTime() return accurate results.
     *
     * @see MaintenanceModeService#isEnabled()
     * @see MaintenanceModeService#getEndTime()
     */
    public function check()
    {
        $enabled = false;
        if ($this->isEnabled()) {
            $enabled = true;
            $endTime = $this->getEndTime();
            $isExpired = $endTime - $this->timeService->getTime() < 0;

            if ($isExpired) {
                $enabled = false;
                $this->disable();
            }
        }

        $this->collector->measure(Metrics::STATISTIC_MAINTENANCE_MODE, $enabled ? 1 : 0);
    }

    /**
     * Returns whether or not maintenance mode is currently enabled.
     *
     * Note:
     *    For this method to work properly, the check() function
     *    must be called once a MINUTE. Otherwise it may return
     *    incorrect results.
     *
     * @see MaintenanceModeService#check()
     * @return bool True if maintenance mode is enabled, false otherwise
     */
    public function isEnabled()
    {
        return $this->getEndTime() > 0;
    }

    /**
     * Returns the end time (as unix timestamp) of the maintenance mode,
     * or 0 if it is not enabled.
     *
     * Note:
     *    For this method to work properly, the check() function
     *    must be called once a MINUTE. Otherwise it may return
     *    incorrect results.
     *
     * @see MaintenanceModeService#check()
     * @return int End time as unix timestamp, or 0 if not enabled.
     */
    public function getEndTime()
    {
        if ($this->filesystem->exists(self::MAINTENANCE_MODE_FILE)) {
            $endTimeStr = trim($this->filesystem->fileGetContents(self::MAINTENANCE_MODE_FILE));
            $endTime = is_numeric($endTimeStr) ? intval($endTimeStr) : 0;

            if ($endTime > 0) {
                return $endTime;
            } else {
                return $this->filesystem->fileMTime(self::MAINTENANCE_MODE_FILE)
                    + self::MAX_ENABLED_TIME_IN_SECONDS;
            }
        } else {
            return 0;
        }
    }

    private function enableMaintenanceViaCloud(int $endTime, string $user = null)
    {
        $status = $this->deviceWebClient->queryWithId(self::MAINTENANCE_STATUS_ENDPOINT);

        // validate result
        if (!array_key_exists('active', $status) || !array_key_exists('scheduledEndTime', $status)) {
            $this->logger->error('MMS0018 Invalid response from device-web');
            throw new Exception('Invalid response from device web');
        }

        $maintenanceActive = $status['active'];

        if ($maintenanceActive) {
            // synchronize intended state by writing keyfile out locally.
            $this->enableMaintenanceViaLocal($status['scheduledEndTime'], $user);
        } else {
            if ($user) {
                $this->logger->info('MMS0014 Asking cloud to enable maintenance on behalf of user', ['user' => $user]);
            } else {
                $this->logger->info('MMS0015 Asking cloud to enable maintenance on behalf of a Datto service.');
            }

            $this->deviceWebClient->queryWithId(self::ENABLE_MAINTENANCE_ENDPOINT, [
                'endTime' => $endTime
            ]);
        }
    }

    private function enableMaintenanceViaLocal(int $endTime, string $user = null)
    {
        $success = $this->filesystem->filePutContents(self::MAINTENANCE_MODE_FILE, $endTime);

        if ($success === false) {
            $this->logger->error('MMS0003 Failed to create file', ['maintenanceModeFile' => self::MAINTENANCE_MODE_FILE]);
            throw new Exception('Cannot enable maintenance mode.');
        }

        if ($user) {
            $this->logger->info('MMS0001 Maintenance mode was enabled by user', ['user' => $user]);
        } else {
            $this->logger->info('MMS0002 Maintenance mode was enabled by a Datto service');
        }
    }

    private function disableMaintenanceViaCloud(string $user = null)
    {
        $status = $this->deviceWebClient->queryWithId(self::MAINTENANCE_STATUS_ENDPOINT);

        // validate result
        if (!isset($status['active'])) {
            $this->logger->error('MMS0019 Invalid response from device-web');
            throw new Exception('Invalid response from device web');
        }

        $maintenanceActive = $status['active'];

        if (!$maintenanceActive) {
            // synchronize intended state by disabling locally.
            $this->disableMaintenanceViaLocal($user);
        } else {
            if ($user) {
                $this->logger->info('MMS0016 Asking cloud to disable maintenance on behalf of user', ['user' => $user]);
            } else {
                $this->logger->info('MMS0017 Asking cloud to disable maintenance on behalf of a Datto service.');
            }

            $this->deviceWebClient->queryWithId(self::DISABLE_MAINTENANCE_ENDPOINT);
        }
    }

    private function disableMaintenanceViaLocal(string $user = null)
    {
        if ($this->filesystem->exists(self::MAINTENANCE_MODE_FILE)) {
            $success = $this->filesystem->unlink(self::MAINTENANCE_MODE_FILE);

            if ($success === false) {
                $this->logger->error('MMS0013 Failed to unlink file', ['maintenanceModeFile' => self::MAINTENANCE_MODE_FILE]);
                throw new Exception('Cannot disable maintenance mode.');
            }

            if ($user) {
                $this->logger->info('MMS0011 Maintenance mode was disabled by user', ['user' => $user]);
            } else {
                $this->logger->info('MMS0012 Maintenance mode was disabled by a Datto service');
            }
        }
    }
}
