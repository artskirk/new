<?php

namespace Datto\System;

use Datto\Asset\AssetService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceConfig;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\RestoreService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * A class to handle reboot scheduling tasks and shutting down the device.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class PowerManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const REBOOT_CONFIG_FILE = "rebootSchedule";
    const SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_FILE = "successfulScheduledRebootIndicator";

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var RestoreService */
    private $restoreService;

    /** @var AssetService */
    private $assetService;

    /** @var RebootReportHelper */
    private $rebootReportHelper;

    public function __construct(
        ProcessFactory $processFactory,
        Filesystem $fileSystem,
        DeviceConfig $deviceConfig,
        RestoreService $restoreService,
        AssetService $assetService,
        RebootReportHelper $rebootReportHelper
    ) {
        $this->processFactory = $processFactory;
        $this->filesystem = $fileSystem;
        $this->deviceConfig = $deviceConfig;
        $this->restoreService = $restoreService;
        $this->assetService = $assetService;
        $this->rebootReportHelper = $rebootReportHelper;
    }

    /**
     * Immediately reboots a device.
     *
     * @return void
     */
    public function rebootNow()
    {
        $this->rebootReportHelper->causedByDevice();
        $this->attemptReboot();
    }

    /**
     * Immediately shuts down a device.
     */
    public function shutdownNow()
    {
        $this->rebootReportHelper->causedByDevice();
        $this->attemptShutdown();
    }

    /**
     * Sets the date on which to schedule device reboot.
     *
     * @param int $timestamp Timestamp at which reboot should be scheduled.
     *
     * @return void
     */
    public function setRebootDateTime($timestamp)
    {
        /* Do sanity checks before setting the data member */
        if (time() < $timestamp) {
            $this->saveSchedule($timestamp, time());
        } else {
            throw new RebootException('Error setting reboot time.');
        }
    }

    /**
     * Checks and eventually performs a scheduled reboot.
     *
     * Reboots the device at requested time unless there are other circumstances
     * when we should abort, i.e. mounted restores, device was rebooted manually
     * etc.
     *
     * @return null
     */
    public function attemptRebootIfScheduled()
    {
        /* @var RebootConfig $config */
        $config = $this->loadSchedule();

        if (!$config) {
            $this->logger->debug('PWM0001 No reboot scheduled.');
            throw new RebootException('No reboot scheduled');
        }

        if ($config->hasFailed()) {
            $this->logger->debug('PWM0002 Reboot was attempted, but failed. Device will not reboot.');
            throw new RebootException('Reboot was attempted, but failed. Device will not reboot.');
        }

        if ($config->isAttemptingReboot()) {
            $this->logger->debug('PWM0008 Device is currently trying to reboot.');
            throw new RebootException('Device is currently trying to reboot.');
        }

        if (!$this->isApplicable($config->getCreatedAt())) {
            $this->cancel(); // We do not want to reboot if a hard reboot has already occurred
            $this->logger->debug('PWM0003 Device was rebooted prior to scheduled reboot, so the scheduled reboot was cancelled.');
            throw new RebootException('Device was rebooted prior to scheduled reboot, so the scheduled reboot was cancelled.');
        }

        // At this point, the user would like to know why a reboot has failed, so lets set a flag
        $shouldReboot = $this->shouldRebootNow($config->getRebootAt());

        if ($shouldReboot) {
            $this->setAttemptFlag($config);

            if ($this->hasMountedRestores()) {
                $this->logger->debug('PWM0004 Device has mounted restores, reboot cannot occur.');
                throw new RebootException('Device has mounted restores, reboot cannot occur.');
            }

            if (!$this->createEmailIndicatorFile()) {
                $this->logger->debug('PWM0005 Failed to write email indicator file, device will not reboot.');
                throw new RebootException('Failed to write email indicator file, device will not reboot.');
            }

            try {
                $this->rebootReportHelper->causedByDevice();
                $this->attemptReboot();
            } catch (Throwable $e) {
                $this->setFailedFlag($config);
                throw $e;
            }
        }
    }

    /**
     * Cancel/clear scheduled reboot.
     */
    public function cancel()
    {
        $this->deviceConfig->clear($this::REBOOT_CONFIG_FILE);
    }

    /**
     * Gets the reboot schedule info data.
     *
     * @return RebootConfig|null NULL, if no scheduled date was set.
     */
    public function getRebootSchedule()
    {
        return $this->loadSchedule();
    }

    /**
     * Gets the last boot time from /proc/stat
     *
     * @return int The epoch of the last boot
     */
    public function getLastBootTime(): int
    {
        $matches = array();
        preg_match('/btime (\d+)/', $this->filesystem->fileGetContents('/proc/stat'), $matches);
        $lastRebootTime = intval($matches[1]);

        return $lastRebootTime;
    }

    /**
     * Reboots the device with no checks.
     * Note that almost always public function rebootNow, in this class, should be used to reboot the device, because
     * it stops backups, stops screenshots etc.  This function is needed for registration, where in certain
     * cases, homePool has not yet been created.
     */
    public function rebootDevice()
    {
        $this->rebootReportHelper->causedByDevice();

        $this->cancel(); //clears the flag

        $process = $this->processFactory
            ->get(['reboot']);

        $process->mustRun();
    }

    /**
     * Checks whether stored schedule info still applies.
     *
     * The scheduled reboot might be no longer applicable if the
     * device was rebooted "by hand". This method checks against
     * uptime info to determine this.
     *
     * @param int $createdAt Timestamp at which the scheduled reboot was requested.
     *
     * @return bool
     */
    private function isApplicable(int $createdAt): bool
    {
        $lastRebootTime = $this->getLastBootTime();
        return $lastRebootTime <= $createdAt;
    }

    /**
     * Checks whether it's the time at which device should be rebooted.
     *
     * @param int $rebootAt Timestamp at which to reboot.
     *
     * @return bool
     */
    private function shouldRebootNow(int $rebootAt): bool
    {
        return $rebootAt <= time();
    }

    /**
     * Checks if there are any mounted restores.
     *
     * @return bool
     */
    private function hasMountedRestores()
    {
        $mountedRestores = $this->restoreService->getAll();
        return count($mountedRestores) > 0;
    }

    /**
     * Saves the reboot schedule in a config key file.
     *
     * @param int $rebootAt Timestamp at which device needs to be rebooted.
     * @param int $createdAt Timestamp at which reboot schedule was created.
     * @param bool $attemptingReboot Whether or not the reboot is being attempted.
     * @param bool $hasFailed Whether the reboot attempt has failed.
     * @return bool Returns true if schedule was saved successfully, false otherwise.
     */
    private function saveSchedule(int $rebootAt, int $createdAt, bool $attemptingReboot = false, bool $hasFailed = false): bool
    {
        // todo convert RebootConfig to use JsonConfigRecord
        $config = new RebootConfig($rebootAt, $createdAt);
        $json = json_encode(array(
            "rebootAt" => $config->getRebootAt(),
            "createdAt" => $config->getCreatedAt(),
            'attemptingReboot' => $attemptingReboot,
            'hasFailed' => $hasFailed
        ));
        $this->deviceConfig->set($this::REBOOT_CONFIG_FILE, $json);

        return $this->deviceConfig->has($this::REBOOT_CONFIG_FILE);
    }

    /**
     * Loads the schedule from the config key file and returns the json string.
     *
     * @return RebootConfig|null Returns the json string if successful, null otherwise.
     */
    private function loadSchedule()
    {
        $data = null;

        $json = $this->deviceConfig->get(self::REBOOT_CONFIG_FILE);
        if ($json) {
            $json = json_decode($json, true);
            $data = new RebootConfig($json['rebootAt'], $json['createdAt'], $json['attemptingReboot'] ?? false, $json['hasFailed'] ?? false);
        }

        return $data;
    }

    /**
     *Create an empty file to indicate successful scheduled reboot
     * to the email notification implementation.
     *
     * @return bool True if the file was written, false otherwise
     */
    private function createEmailIndicatorFile(): bool
    {
        $this->deviceConfig->set($this::SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_FILE, "Reboot is scheduled");

        return $this->deviceConfig->has($this::SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_FILE);
    }

    /**
     * Temporarily pauses all cron, backups, screenshots, and attempts to reboot the device.
     */
    private function attemptReboot()
    {
        try {
            $this->rebootDevice();
        } catch (Throwable $e) {
            $this->deviceConfig->clear($this::SUCCESSFUL_SCHEDULED_REBOOT_INDICATOR_FILE);
            $message = $e->getMessage();
            $this->logger->debug('PWM0006 ' . $message);
            throw new RebootException("There was an error rebooting the device");
        }
    }

    /**
     * Temporarily pauses all cron, backups, screenshots, and attempts to shutdown the device.
     */
    private function attemptShutdown()
    {
        try {
            $this->processFactory
                ->get(['shutdown', 'now'])
                ->mustRun();
        } catch (Throwable $e) {
            $this->logger->error("PWM0007 Error encountered attempting to gracefully shut down device", ['exception' => $e]);
            throw new Exception("There was an error shutting down the device");
        }
    }

    /**
     * Sets the attemptingReboot flag in the config to true.
     *
     * @param RebootConfig $config The config object to alter
     */
    private function setAttemptFlag(RebootConfig $config)
    {
        $this->saveSchedule(
            $config->getRebootAt(),
            $config->getCreatedAt(),
            $attemptingReboot = true
        );
    }

    /**
     * Indicate that the attempt to reboot failed.
     *
     * @param RebootConfig $config The config object to alter
     */
    private function setFailedFlag(RebootConfig $config)
    {
        $this->saveSchedule(
            $config->getRebootAt(),
            $config->getCreatedAt(),
            $attemptingReboot = false,
            $hasFailed = true
        );
    }
}
