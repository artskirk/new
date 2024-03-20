<?php

namespace Datto\System\Migration\ZpoolReplace\Stage;

use Datto\AppKernel;
use Datto\Log\LoggerFactory;
use Datto\System\MaintenanceModeService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Migration\ZpoolReplace\ZpoolMigrationValidationService;
use Datto\Common\Resource\Sleep;
use Datto\ZFS\ZpoolStatus;
use Datto\ZFS\ZpoolService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Uses zpool replace to swap out drives
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class ZpoolReplaceStage extends AbstractMigrationStage
{
    const MIGRATE_LOOP_WAIT_TIME_SECONDS = 10;
    const MAINTENANCE_MODE_ENABLE_SECONDS = 300;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ZpoolService */
    private $zpoolService;

    /** @var ZpoolMigrationValidationService */
    private $validator;

    /** @var MaintenanceModeService */
    private $maintenanceService;

    /** @var Sleep */
    private $sleep;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface|null $logger
     * @param ZpoolService|null $zpoolService
     * @param ZpoolMigrationValidationService|null $zpoolMigrationValidationService
     * @param MaintenanceModeService|null $maintenanceService
     * @param Sleep|null $sleep
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger = null,
        ZpoolService $zpoolService = null,
        ZpoolMigrationValidationService $zpoolMigrationValidationService = null,
        MaintenanceModeService $maintenanceService = null,
        Sleep $sleep = null
    ) {
        parent::__construct($context);
        
        $this->logger = $logger ?: LoggerFactory::getDeviceLogger();
        $this->zpoolService = $zpoolService ?: new ZpoolService();
        $this->validator = $zpoolMigrationValidationService ?: new ZpoolMigrationValidationService();
        $this->maintenanceService = $maintenanceService ?: AppKernel::getBootedInstance()->getContainer()->get(MaintenanceModeService::class);
        $this->sleep = $sleep ?: new Sleep();
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        $this->migrateStorage();
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
        $this->disableMaintenanceModeIfNeeded();
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        $this->disableMaintenanceModeIfNeeded();
    }

    /**
     * Attempts to replace current homepool with a new set of drives.
     */
    protected function migrateStorage()
    {
        $this->logger->info("MIG0018 Starting Zpool Replace Migration Process...");

        $sourceDriveIds = $this->context->getSources();
        $destinationDriveIds = $this->context->getTargets();
        $this->logger->debug('MIG0040 Target devices', ['destinationDriveIds' => $destinationDriveIds]);

        $zpoolStatus = $this->zpoolService->getZpoolStatus(ZpoolService::HOMEPOOL);
        $this->logger->info('MIG0019 Current Zpool Status before migration', ['poolStatusSummary' => $zpoolStatus->getSummarizedStatus()]);

        while ($this->migrationCanProceed($zpoolStatus, $sourceDriveIds, $destinationDriveIds)) {
            $this->enableMaintenanceModeIfNeeded();

            if (!$zpoolStatus->isZpoolResilvering()) {
                $this->logger->info("MIG0020 Resilvering NOT running, getting next drive pair to replace...");
                // We need to start resilvering another drive or first drive.
                list($source, $destination) = $this->getSourceDestinationPair(
                    $zpoolStatus,
                    $sourceDriveIds,
                    $destinationDriveIds
                );

                $this->logger->info('MIG0021 Replacing source with destination', ['source' => $source, 'destination' => $destination]);
                $this->zpoolService->forceReplaceDrive(ZpoolService::HOMEPOOL, $source, $destination);
            } else {
                $this->logger->info("MIG0022 Resilvering running...");
            }

            $this->sleep->sleep(static::MIGRATE_LOOP_WAIT_TIME_SECONDS);
            $zpoolStatus = $this->zpoolService->getZpoolStatus(ZpoolService::HOMEPOOL);
            $this->logger->debug('MIG0023 Current Zpool Status', ['poolStatusSummary' => $zpoolStatus->getSummarizedStatus()]);
        }

        $this->logger->info("MIG0024 Zpool replace complete.");
    }

    /**
     * Detect any drives that are no longer connected to the system, but are part of an on-going migration. If a
     * disconnected drive is detected, it will be detached from the zpool to cancel the replace.
     *
     * @param ZpoolStatus $zpoolStatus
     */
    private function detectAndHandleDisconnectedDrives(ZpoolStatus $zpoolStatus)
    {
        $this->logger->info("MIG0028 Detecting disconnected drives ...");
        $replacementGroup = array_values($zpoolStatus->getReplacementGroup());

        try {
            if (!empty($replacementGroup) && count($replacementGroup[0]['devices']) === 2) {
                $replacementDriveIds = array_keys($replacementGroup[0]['devices']);

                $sourceDriveId = $replacementDriveIds[0];
                $targetDriveId = $replacementDriveIds[1];

                $this->validator->validateAllConnectedDisks($zpoolStatus, [$sourceDriveId], [$targetDriveId]);
            }
        } catch (\Exception $e) {
            $this->logger->critical('MIG0029 An error occurred, now attempting to detach target drive', ['exception' => $e, 'targetDriveId' => $targetDriveId]);

            $this->zpoolService->detach(ZpoolService::HOMEPOOL, $targetDriveId);

            throw new \Exception("One or more drives being replaced is no longer connected: " . $e->getMessage());
        }
    }

    /**
     * Enable maintenance mode if needed
     */
    private function enableMaintenanceModeIfNeeded()
    {
        if ($this->context->shouldEnableMaintenanceMode()) {
            $this->logger->info("MIG0017 Enabling maintenance mode...");
            $this->maintenanceService->enableForSeconds(static::MAINTENANCE_MODE_ENABLE_SECONDS);
        }
    }

    /**
     * Disable maintenance mode if needed
     */
    private function disableMaintenanceModeIfNeeded()
    {
        if ($this->context->shouldEnableMaintenanceMode()) {
            $this->logger->info("MIG0017 Disabling maintenance mode...");
            $this->maintenanceService->disable();
        }
    }

    /**
     * Ensure that the migration will be able to take place. If not throws an exception and sets an error.
     *
     * @param ZpoolStatus $zpoolStatus
     * @param string[] $sourceDriveIds
     * @param string[] $destinationDriveIds
     * @return bool
     */
    private function migrationCanProceed(
        ZpoolStatus $zpoolStatus,
        array $sourceDriveIds,
        array $destinationDriveIds
    ): bool {
        $this->detectAndHandleDisconnectedDrives($zpoolStatus);

        if ($zpoolStatus->isZpoolResilvering()) {
            return true;
        }

        $this->validator->validate($sourceDriveIds, $destinationDriveIds, $zpoolStatus);

        $areDrivesLeft = ZpoolMigrationValidationService::areDrivesLeftToMigrate($zpoolStatus, $destinationDriveIds);

        if ($areDrivesLeft) {
            $this->logger->info("MIG0025 There are still drives to migrate, continuing...");
        } else {
            $this->logger->info("MIG0026 There are no more drives to migrate.");
        }

        return $areDrivesLeft;
    }

    /**
     * Picks a replacement pair source - new.
     *
     * @param ZpoolStatus $zpoolStatus
     * @param string[] $sourceDriveIds
     * @param string[] $destinationDriveIds
     * @return string[]
     */
    private function getSourceDestinationPair(
        ZpoolStatus $zpoolStatus,
        array $sourceDriveIds,
        array $destinationDriveIds
    ): array {
        $poolDrivesIds = $zpoolStatus->getPoolDriveIds();

        $unprocessedSourceDriveIds = array_values(array_intersect($poolDrivesIds, $sourceDriveIds));
        $unprocessedDestinationDriveIds = array_values(array_diff($destinationDriveIds, $poolDrivesIds));

        return [$unprocessedSourceDriveIds[0], $unprocessedDestinationDriveIds[0]];
    }
}
