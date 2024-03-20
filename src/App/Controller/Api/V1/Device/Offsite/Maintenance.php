<?php

namespace Datto\App\Controller\Api\V1\Device\Offsite;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Resource\DateTimeService;

/**
 * API endpoints to expose device-wide offsite sync maintenance functionality.
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
 * @author Christopher R. Wicks <cwicks@datto.com>
 * @codeCoverageIgnore
 */
class Maintenance
{
    /** @var SpeedSyncMaintenanceService */
    private $speedSyncService;

    public function __construct(
        SpeedSyncMaintenanceService $speedSyncService
    ) {
        $this->speedSyncService = $speedSyncService;
    }

    /**
     * Is offsiting currently enabled?
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return bool
     */
    public function isEnabled()
    {
        return $this->speedSyncService->isEnabled();
    }

    /**
     * Pause offsite transfers and builds for the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *   "duration" = @Symfony\Component\Validator\Constraints\Range(min=0, max=87601)
     * })
     * @param int $duration The number of hours for which offsite sync should pause, from 0 to 87601 (max from device-web)
     * @return int The timestamp at which offsite sync will resume
     */
    public function pause($duration)
    {
        $this->speedSyncService->pause($duration * DateTimeService::SECONDS_PER_HOUR);
        return $this->speedSyncService->getResumeTime();
    }

    /**
     * Resume offsite builds and transfers for the device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @return bool True on success
     */
    public function resume()
    {
        $this->speedSyncService->resume();
        return true;
    }

    /**
     * Is offsite sync currently paused for the device?
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return bool
     */
    public function isPaused()
    {
        return $this->speedSyncService->isDevicePaused();
    }

    /**
     * Get the time when a device-wide offsite sync pause will expire.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return int
     */
    public function getResumeTime()
    {
        return $this->speedSyncService->getResumeTime();
    }

    /**
     * Is offsite sync on an indefinite delay?
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return bool
     */
    public function isDelayIndefinite()
    {
        return $this->speedSyncService->isDelayIndefinite();
    }

    /**
     * Set maximum number of concurrent offsite syncs.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @param int $maxSyncs
     */
    public function setMaxConcurrentSyncs($maxSyncs): void
    {
        $this->speedSyncService->setMaxConcurrentSyncs($maxSyncs);
    }

    /**
     * Get the maximum number of concurrent offsite syncs.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return int
     */
    public function getMaxConcurrentSyncs(): int
    {
        return $this->speedSyncService->getMaxConcurrentSyncs();
    }
}
