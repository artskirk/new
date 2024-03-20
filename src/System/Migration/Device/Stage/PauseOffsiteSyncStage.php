<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Resource\DateTimeService;
use Datto\System\Api\DeviceApiClientService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Pause and resume offsite sync of local and remote devices
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class PauseOffsiteSyncStage extends AbstractMigrationStage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncService;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param SpeedSyncMaintenanceService $speedSyncService
     * @param DeviceApiClientService $deviceClient
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        SpeedSyncMaintenanceService $speedSyncService,
        DeviceApiClientService $deviceClient
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->speedSyncService = $speedSyncService;
        $this->deviceClient = $deviceClient;
    }

    /**
     * @inheritdoc
     * Pause offsite sync on local and remote devices
     */
    public function commit()
    {
        $this->pauseOffsiteSyncLocal();
        $this->pauseOffsiteSyncRemote();
    }

    /**
     * @inheritdoc
     * Resume offsite sync on local and remote devices
     */
    public function cleanup()
    {
        $this->resumeOffsiteSyncLocal();
        $this->resumeOffsiteSyncRemote();
    }

    /**
     * @inheritdoc
     * Resume offsite sync on local and remote devices
     */
    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * Disable offsite sync on local device
     */
    private function pauseOffsiteSyncLocal()
    {
        $this->speedSyncService->pause(MaintenanceModeStage::MAINTENANCE_PERIOD_IN_HOURS * DateTimeService::SECONDS_PER_HOUR);
    }

    /**
     * Enable offsite sync on local device
     */
    private function resumeOffsiteSyncLocal()
    {
        $this->speedSyncService->resume();
    }

    /**
     * Disable offsite sync on remote device
     */
    private function pauseOffsiteSyncRemote()
    {
        $this->deviceClient->call(
            'v1/device/offsite/maintenance/pause',
            ['duration' => MaintenanceModeStage::MAINTENANCE_PERIOD_IN_HOURS]
        );
    }

    /**
     * Enable offsite sync on remote device
     */
    private function resumeOffsiteSyncRemote()
    {
        $this->deviceClient->call('v1/device/offsite/maintenance/resume', []);
    }
}
