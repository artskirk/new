<?php

namespace Datto\App\Controller\Api\V1\Device\Offsite\Replication;

use Datto\Backup\SecondaryReplicationService;

/**
 * This is an API end-point for enabling/disabling secondary
 * replication.
 *
 * Secondary replication involves replicating backup data from
 * the primary Datto cloud data center to a secondary Datto
 * location.
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
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class Secondary
{
    /** @var SecondaryReplicationService */
    private $replicationService;

    public function __construct(
        SecondaryReplicationService $replicationService
    ) {
        $this->replicationService = $replicationService;
    }

    /**
     * Checks whether secondary replication is available for this
     * device location.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_SECONDARY")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return bool Returns true if secondary replication is available
     * for this device location, false otherwise.
     */
    public function isAvailable()
    {
        return $this->replicationService->isAvailable();
    }

    /**
     * Checks whether secondary replication is enabled on this device.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_SECONDARY")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_READ")
     * @return bool Returns true if secondary replication is enabled,
     * false otherwise.
     */
    public function isEnabled()
    {
        return $this->replicationService->isEnabled();
    }

    /**
     * Enables/disables secondary replication on the device based on
     * the given flag.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE")
     * @Datto\App\Security\RequiresFeature("FEATURE_OFFSITE_SECONDARY")
     * @Datto\App\Security\RequiresPermission("PERMISSION_OFFSITE_WRITE")
     * @param bool $flag If it's true, enable secondary replication,
     * disable otherwise.
     */
    public function setEnabled($flag): void
    {
        switch ($flag) {
            case true:
                $this->replicationService->enable();
                break;
            case false:
                $this->replicationService->disable();
                break;
        }
    }
}
