<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\System\Hardware;
use Datto\Log\DeviceLoggerInterface;

/**
 * Cache the hypervisor config key
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class CacheHypervisorTask implements ConfigRepairTaskInterface
{
    const KEY_FILE = 'hypervisor';

    /** @var DeviceConfig */
    private $deviceConfig;
    /** @var DeviceLoggerInterface */
    private $logger;
    /** @var Hardware */
    private $hardware;

    /**
     * @param DeviceLoggerInterface $logger
     * @param DeviceConfig $deviceConfig
     * @param Hardware $hardware
     */
    public function __construct(DeviceLoggerInterface $logger, DeviceConfig $deviceConfig, Hardware $hardware)
    {
        $this->deviceConfig = $deviceConfig;
        $this->logger = $logger;
        $this->hardware = $hardware;
    }

    public function run(): bool
    {
        $hypervisorType = $this->hardware->detectHypervisor();
        $hypervisor = $hypervisorType !== null ? $hypervisorType->value() : null;

        $keyExists = $this->deviceConfig->has(static::KEY_FILE);

        if (empty($hypervisor) && $keyExists) {
            $this->logger->warning('CFG0006 clearing device key \'hypervisor\'');
            $this->deviceConfig->clear(static::KEY_FILE);
            return true;
        } elseif (!empty($hypervisor) && $this->deviceConfig->get(static::KEY_FILE, '') !== $hypervisor) {
            $this->logger->info('CFG0040 setting device key \'hypervisor\'', ['hypervisor' => $hypervisor]);
            $this->deviceConfig->set(static::KEY_FILE, $hypervisor);
            return true;
        }

        return false;
    }
}
