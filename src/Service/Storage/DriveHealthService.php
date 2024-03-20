<?php

namespace Datto\Service\Storage;

use Datto\Config\DeviceState;
use Datto\Core\Drives\AbstractDrive;
use Datto\Core\Drives\DriveFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Psr\Log\LoggerAwareInterface;

/**
 * Performs drive health checking functionality.
 */
class DriveHealthService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DRIVE_HEALTH_CACHE_KEY = 'driveHealth';
    private const MISSING_DRIVES_KEY = 'missingDrives';

    private DriveFactory $driveFactory;
    private DeviceState $deviceState;
    private DriveErrorChecker $errorChecker;
    private Collector $collector;

    public function __construct(
        DriveFactory $driveFactory,
        DeviceState $deviceState,
        DriveErrorChecker $errorChecker,
        Collector $collector
    ) {
        $this->driveFactory = $driveFactory;
        $this->deviceState = $deviceState;
        $this->errorChecker = $errorChecker;
        $this->collector = $collector;
    }

    /**
     * Returns the cached drive health. If no cache exists, it will refresh the cache first.
     *
     * @return array The cached drive information
     */
    public function getDriveHealth(): array
    {
        $cache = $this->readCache();

        // If there is nothing cached, refresh the cache and try to read it again.
        if (!$cache) {
            $this->logger->debug('DHS0001 Drive cache is empty');
            $this->updateDriveHealth();
            $cache = $this->readCache();
        }
        return $cache;
    }

    /**
     * Determine from the drive cache whether there are any drives on this system with errors
     *
     * @return bool True if there are any detected drive errors
     */
    public function driveErrorsExist(): bool
    {
        $cache = $this->readCache();
        foreach ($cache as $drive) {
            $smartIsReliable = $drive['smartReliable'] ?? true;
            if ($smartIsReliable && $drive['errors']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Updates the cached drive health information, and runs through the suite of drive health checks
     */
    public function updateDriveHealth(): void
    {
        $this->logger->debug('DHS0002 Updating drive health');

        // Grab the current list of drives, as well as the drive cache
        $drives = $this->driveFactory->getDrives();
        $cache = $this->readCache();

        // Check each drive for errors, extending the drive objects
        $drivesWithErrors = [];
        foreach ($drives as $drive) {
            $this->errorChecker->checkForErrors($drive);
            if ($drive->getErrorCount() !== 0) {
                if ($drive->isSmartReliable()) {
                    $drivesWithErrors[] = $drive;
                } else {
                    $this->logger->warning('DHS3002 Unreliable SMART errors ignored for drive', [
                        'drive' => json_decode(json_encode($drive), true),
                    ]);
                }
            }
        }

        // Log an error containing the information from the drives with errors
        if ($drivesWithErrors) {
            $this->logger->error('DHS3001 One or more drives have errors', [
                'drives' => json_decode(json_encode($drivesWithErrors), true)
            ]);
        }

        // If we have cached drive information, we can run some checks using it.
        if ($cache) {
            $this->checkForMissing($cache, $drives);
            $this->checkForNew($cache, $drives);
        }

        // Send some metrics about the drives on this system
        $this->collector->measure(Metrics::DRIVES_WITH_ERRORS, count($drivesWithErrors));
        $this->collector->measure(Metrics::DRIVES_ACTIVE, count($drives));
        $this->collector->measure(Metrics::DRIVES_MISSING, count($this->getMissing()));

        // Write the drive health information to the cache
        $this->writeCache($drives);
    }

    /**
     * Retrieve last known status of any missing drives
     *
     * @return array Array of drives that no longer appear when updating drive health, and have not yet been ack'ed
     */
    public function getMissing(): array
    {
        return json_decode($this->deviceState->get(self::MISSING_DRIVES_KEY), true) ?: [];
    }

    /**
     * Acknowledge the removal of a specific disk, removing it from the list of missing drives and clearing the alert
     *
     * @param string $serial The serial number of the drive to acknowledge
     */
    public function acknowledgeMissing(string $serial): void
    {
        $this->logger->info('DHS1005 Acknowledged missing drive', ['serial' => $serial]);

        // Grab the array of missing drives, and filter out any whose serial number matches the provided
        $missing = $this->getMissing();
        $missing = array_filter($missing, fn($drive) => strtolower($drive['serial']) !== strtolower($serial));
        $this->writeMissing($missing);
    }

    /**
     * Clear the entire list of missing drives
     */
    public function clearMissing(): void
    {
        $this->logger->info('DHS1006 Clearing list of missing drives');
        $this->deviceState->clear(self::MISSING_DRIVES_KEY);
    }

    /**
     * @param array $cache The deserialized JSON from the cached drive health
     * @param AbstractDrive[] $drives The drive information read from the system just now
     */
    private function checkForMissing(array $cache, array $drives): void
    {
        $this->logger->debug('DHS0003 Checking for missing drives');

        // Grab the list serial numbers of drives currently in the system
        $currentSerials = array_map(fn($drive): string => $drive->getSerial(), $drives);

        // Filter the cache to remove drives that are still present on the system, leaving only drives
        // that were present in the cache, but NOT present in the live drive data.
        $missing = array_values(array_filter($cache, fn($cached) => !in_array($cached['serial'], $currentSerials)));

        if (!empty($missing)) {
            $missingSerials = array_column($missing, 'serial');
            $this->logger->critical('DHS4001 One or more expected drives is missing', [
                'currentSerials' => $currentSerials,
                'missingSerials' => $missingSerials,
                'missingDrives' => $missing,
            ]);

            // Pull the existing list of missing drives and add the newly-detected missing drives to it
            $savedMissing = $this->getMissing();
            $missing = array_merge($savedMissing, $missing);
            $this->writeMissing($missing);
        }
    }

    /**
     * @param array $cache The deserialized JSON from the cached drive health
     * @param AbstractDrive[] $drives The drive information read from the system just now
     */
    private function checkForNew(array $cache, array $drives): void
    {
        $this->logger->debug('DHS0004 Checking for new drives');

        // Grab the list of serials that are in the drive cache
        $cachedSerials = array_column($cache, 'serial');

        // Filter the list of drives to remove drives that aren't already cached, leaving only the ones that are
        // new since the last cache update.
        $new = array_values(array_filter($drives, fn($drive) => !in_array($drive->getSerial(), $cachedSerials)));

        if (!empty($new)) {
            // Any new drives should first be checked against the list of missing drives
            $missingSerials = array_column($this->getMissing(), 'serial');
            foreach ($new as $newDrive) {
                if (in_array($newDrive->getSerial(), $missingSerials)) {
                    $this->logger->warning('DHS2001 A previously missing drive has returned', [
                        'drive' => $newDrive
                    ]);
                    $this->acknowledgeMissing($newDrive->getSerial());
                }
            }

            $this->logger->info('DHS1001 One or more new storage drives detected', [
                'cached' => $cachedSerials,
                'new' => $new
            ]);
        }
    }

    private function writeMissing(array $missing): void
    {
        $this->deviceState->set(self::MISSING_DRIVES_KEY, json_encode($missing));
    }

    private function readCache(): array
    {
        $cache = json_decode($this->deviceState->get(self::DRIVE_HEALTH_CACHE_KEY) ?? 'null', true) ?: [];

        // Cache is updated every ~6h, so after an IBU upgrade the device may be using cache that was generated on
        // an older IBU for a couple of hours. This adds missing keys with default values. Accurate values will
        // be persisted in the cache during the next refresh.
        foreach ($cache as &$drive) {
            $drive['smartReliable'] ??= true;
        }

        return $cache;
    }

    private function writeCache(array $drives): void
    {
        $this->deviceState->set(self::DRIVE_HEALTH_CACHE_KEY, json_encode($drives));
    }
}
