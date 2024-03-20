<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;

/**
 * Remove AltoXL flag on virtual devices to allow Virtualize Via Hypervisor
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class VirtualRemoveAltoxlTask implements ConfigRepairTaskInterface
{
    const KEY_FILE_ISVIRTUAL = 'isVirtual';
    const KEY_FILE_ISALTOXL = 'isAltoXL';

    /** @var DeviceConfig */
    private $deviceConfig;
    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param DeviceLoggerInterface $logger
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        DeviceConfig $deviceConfig
    ) {
        $this->deviceConfig = $deviceConfig;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        if ($this->deviceConfig->has(static::KEY_FILE_ISVIRTUAL) &&
            $this->deviceConfig->has(static::KEY_FILE_ISALTOXL)
        ) {
            $this->deviceConfig->clear(static::KEY_FILE_ISALTOXL);
            $this->logger->warning('CFG0030 Removing device key', ['key' => static::KEY_FILE_ISALTOXL]);
            return true;
        }
        return false;
    }
}
