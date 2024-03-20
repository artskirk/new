<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceState;
use Datto\Log\DeviceLoggerInterface;

/**
 * Remove obsolete device state files
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class RemoveObsoleteDeviceStateFiles implements ConfigRepairTaskInterface
{
    private const FILES_TO_BE_REMOVED = [
        'telegraf.env'
    ];

    private DeviceLoggerInterface $logger;
    private DeviceState $deviceState;

    public function __construct(DeviceLoggerInterface $logger, DeviceState $deviceState)
    {
        $this->logger = $logger;
        $this->deviceState = $deviceState;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        $changesOccurred = false;

        foreach (self::FILES_TO_BE_REMOVED as $file) {
            $changesOccurred |= $this->checkAndRemove($file);
        }

        return $changesOccurred;
    }

    private function checkAndRemove(string $file): bool
    {
        if ($this->deviceState->has($file)) {
            $fullFilePath = $this->deviceState->getKeyFilePath($file);
            $this->logger->info('CFG0017 clearing device state', ['file' => $fullFilePath]);
            $this->deviceState->clear($file);
            return true;
        }

        return false;
    }
}
