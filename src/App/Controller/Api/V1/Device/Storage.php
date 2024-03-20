<?php

namespace Datto\App\Controller\Api\V1\Device;

use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\ZFS\ZpoolService;

/**
 * Handle storage operations on the device, like storage migration.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class Storage
{
    /** @var StorageService */
    private $storageService;

    /** @var ZpoolService */
    private $zpoolService;

    public function __construct(
        StorageService $storageService,
        ZpoolService $zpoolService
    ) {
        $this->storageService = $storageService;
        $this->zpoolService = $zpoolService;
    }

    /**
     * Get all physical devices.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_UPGRADE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @return array
     */
    public function getPhysicalDevices() : array
    {
        $toArray = function (StorageDevice $device) {
            return $device->toArray();
        };

        return array_map($toArray, $this->storageService->getPhysicalDevices());
    }

    /**
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_UPGRADE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @param array $newDriveIds
     * @param string $raidLevel
     * @return bool
     */
    public function addDriveGroup(array $newDriveIds, string $raidLevel)
    {
        $this->zpoolService->forceAddDriveGroup(ZpoolService::HOMEPOOL, $newDriveIds, $raidLevel);

        return true;
    }
}
