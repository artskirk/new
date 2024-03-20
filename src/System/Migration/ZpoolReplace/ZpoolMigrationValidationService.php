<?php

namespace Datto\System\Migration\ZpoolReplace;

use Datto\Log\LoggerFactory;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\ZFS\ZpoolService;
use Datto\ZFS\ZpoolStatus;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Logic to determine if a zpool replace migration is feasible.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ZpoolMigrationValidationService
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var StorageService */
    private $storageService;

    /** @var ZpoolService */
    private $zpoolService;

    /**
     * @param DeviceLoggerInterface|null $logger
     * @param StorageService|null $storageService
     * @param ZpoolService|null $zpoolService
     */
    public function __construct(
        DeviceLoggerInterface $logger = null,
        StorageService $storageService = null,
        ZpoolService $zpoolService = null
    ) {
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->storageService = $storageService ?: new StorageService();
        $this->zpoolService = $zpoolService ?: new ZpoolService();
    }

    /**
     * Checks if there are enough disks and if the provided disks are connected.
     *
     * @param string[] $sourceDriveIds
     * @param string[] $destinationDriveIds
     * @param ZpoolStatus|null $zpoolStatus
     */
    public function validate(
        array $sourceDriveIds,
        array $destinationDriveIds,
        ZpoolStatus $zpoolStatus = null
    ) {
        $this->logger->debug("ZMV0000 Validating migration...");
        $zpoolStatus = $zpoolStatus ?: $this->zpoolService->getZpoolStatus(ZpoolService::HOMEPOOL);

        $zpoolDrivesIds =  $zpoolStatus->getPoolDriveIds();

        $unprocessedSourceDriveIds = array_values(array_intersect($zpoolDrivesIds, $sourceDriveIds));
        $unprocessedDestinationDriveIds = array_values(array_diff($destinationDriveIds, $zpoolDrivesIds));

        if (count($unprocessedSourceDriveIds) !== count($unprocessedDestinationDriveIds)) {
            $this->logger->error(
                'ZMV0001 Error: There are not the same number of Unprocessed source and destination drives',
                ['sourceDrives' => $unprocessedSourceDriveIds, 'destinationDrives' => $unprocessedDestinationDriveIds]
            );
            $msg =
                "Error: There are not the same number of Unprocessed source and destination drives: " .
                " - SOURCE DRIVES: " . var_export($unprocessedSourceDriveIds, true) .
                " - DESTINATION DRIVES: " . var_export($unprocessedDestinationDriveIds, true) . "\n";
            throw new \Exception($msg);
        }

        $this->validateAllConnectedDisks($zpoolStatus, $sourceDriveIds, $destinationDriveIds);
    }

    /**
     * Returns whether or not there are drives left to migrate.
     *
     * @param ZpoolStatus $poolStatus
     * @param string[] $destinationDriveIds
     * @return bool
     */
    public static function areDrivesLeftToMigrate(ZpoolStatus $poolStatus, array $destinationDriveIds) : bool
    {
        $poolDriveIds = $poolStatus->getPoolDriveIds();

        $unprocessedDestinationDriveIds = array_values(array_diff($destinationDriveIds, $poolDriveIds));

        return count($unprocessedDestinationDriveIds) > 0;
    }

    /**
     * Checks if all disks are connected.
     *
     * @param ZpoolStatus $zpoolStatus
     * @param string[] $sourceDriveIds
     * @param string[] $destinationDriveIds
     */
    public function validateAllConnectedDisks(
        ZpoolStatus $zpoolStatus,
        array $sourceDriveIds,
        array $destinationDriveIds
    ) {
        $connectedDestinationDrives = [];

        $this->logger->info('ZMV0002 Validating that all destination drives are properly connected...');
        foreach ($destinationDriveIds as $destinationDiskId) {
            $connectedDestinationDrive = $this->storageService->getPhysicalDeviceById($destinationDiskId);
            if (!$connectedDestinationDrive) {
                throw new \Exception("Disk: $destinationDiskId is not connected.");
            }
            $this->logger->info('ZMV0003 Disk is properly connected.', ['destinationDiskId' => $destinationDiskId]);
            $connectedDestinationDrives[] = $connectedDestinationDrive;
        }

        $unprocessedSourceDriveIds = array_values(array_intersect($zpoolStatus->getPoolDriveIds(), $sourceDriveIds));

        $sourceDrives = [];

        $this->logger->info('ZMV0004 Getting source drives information...');
        foreach ($unprocessedSourceDriveIds as $sourceDriveId) {
            $sourceDrive = $this->storageService->getPhysicalDeviceById($sourceDriveId);
            if (!$sourceDrive) {
                $this->logger->critical('ZMV0005 SOURCE Disk is not connected. Something is going really wrong.', ['sourceDriveId' => $sourceDriveId]);

                $msg = "SOURCE Disk: $sourceDriveId is not connected. Something is going really wrong.";
                throw new \Exception($msg);
            }
            $sourceDrives[] = $sourceDrive;
        }

        $this->logger->info('ZMV0006 Validating source-destination disk sizes...');
        $this->validateCorrectDiskSizes($sourceDrives, $connectedDestinationDrives);
    }

    /**
     * @param StorageDevice[] $unprocessedSourceDrives
     * @param StorageDevice[] $unprocessedDestinationDrives
     */
    private function validateCorrectDiskSizes(array $unprocessedSourceDrives, array $unprocessedDestinationDrives)
    {
        foreach ($unprocessedDestinationDrives as $destinationDrive) {
            foreach ($unprocessedSourceDrives as $sourceDrive) {
                if ($destinationDrive->getCapacity() < $sourceDrive->getCapacity()) {
                    $this->logger->error(
                        'ZMV0007 Destination drive has smaller capacity than source drive',
                        ['destinationDriveId' => $destinationDrive->getId(), 'sourceDriveId' => $sourceDrive->getId()]
                    );
                    $msg = "Destination drive: {$destinationDrive->getId()} " .
                        "has smaller capacity than {$sourceDrive->getId()}";
                    throw new Exception($msg);
                }
            }
            $this->logger->info('ZMV0008 Destination drive has a valid capacity', ['destinationDriveId' => $destinationDrive->getId()]);
        }
        $this->logger->info('ZMV0009 All drives have valid capacity, migration can continue.');
    }
}
