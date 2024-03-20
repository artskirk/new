<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\System\Api\DeviceApiClientService;
use Datto\System\MaintenanceModeService;
use Datto\System\Migration\Context;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\Log\DeviceLoggerInterface;

/**
 * Set and clear maintenance mode of local and remote devices
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class MaintenanceModeStage extends AbstractMigrationStage
{
    const MAINTENANCE_PERIOD_IN_HOURS = 48;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var MaintenanceModeService */
    private $maintenanceModeService;

    /** @var DeviceApiClientService */
    private $deviceClient;

    /**
     * @param Context $context
     * @param DeviceLoggerInterface $logger
     * @param MaintenanceModeService $maintenanceModeService
     * @param DeviceApiClientService $deviceClient
     */
    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        MaintenanceModeService $maintenanceModeService,
        DeviceApiClientService $deviceClient
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->maintenanceModeService = $maintenanceModeService;
        $this->deviceClient = $deviceClient;
    }

    /**
     * @inheritdoc
     * Enable maintenance mode on local and remote devices
     */
    public function commit()
    {
        $this->enableMaintenanceModeLocal();
        $this->enableMaintenanceModeRemote();
    }

    /**
     * @inheritdoc
     * Disable maintenance mode on local and remote devices
     */
    public function cleanup()
    {
        $this->disableMaintenanceModeLocal();
        $this->disableMaintenanceModeRemote();
    }

    /**
     * @inheritdoc
     * Disable maintenance mode on local and remote devices
     */
    public function rollback()
    {
        $this->cleanup();
    }

    /**
     * Enable maintenance mode on local device
     */
    private function enableMaintenanceModeLocal()
    {
        $this->maintenanceModeService->enable(static::MAINTENANCE_PERIOD_IN_HOURS);
    }

    /**
     * Disable maintenance mode on local device
     */
    private function disableMaintenanceModeLocal()
    {
        $this->maintenanceModeService->disable();
    }

    /**
     * Enable maintenance mode on remote device
     */
    private function enableMaintenanceModeRemote()
    {
        $this->deviceClient->call(
            'v1/device/settings/maintenanceMode/enable',
            ['hours' => static::MAINTENANCE_PERIOD_IN_HOURS]
        );
    }

    /**
     * Disable maintenance mode on remote device
     */
    private function disableMaintenanceModeRemote()
    {
        $this->deviceClient->call('v1/device/settings/maintenanceMode/disable', []);
    }
}
