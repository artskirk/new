<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;

/**
 * Ensure deviceOrigination key exists.
 * The deviceOrigination key indicates whether the device was imaged by a partner or by the datto build team.
 *
 * @author Jason Lodice <jlodice@datto.com>
 */
class DeviceOriginationRepairTask implements ConfigRepairTaskInterface
{
    const KEY_FILE = 'deviceOrigination';

    /** @var DeviceConfig */
    private $deviceConfig;
    /** @var DeviceLoggerInterface */
    private $logger;

    /**
     * @param DeviceLoggerInterface $logger
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(DeviceLoggerInterface $logger, DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        if (!$this->deviceConfig->has(static::KEY_FILE)) {
            $model = $this->deviceConfig->get('model');
            $isConverted = preg_match('/^[Ss]3[Ii]\d+$/', $model);
            $value = $isConverted ? 'converted' : 'datto';
            $this->logger->warning('CFG0001 setting device key', ['keyFile' => static::KEY_FILE, 'value' => $value]);
            $this->deviceConfig->set(static::KEY_FILE, $value);
            return true;
        }

        return false;
    }
}
