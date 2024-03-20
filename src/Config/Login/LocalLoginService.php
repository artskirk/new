<?php

namespace Datto\Config\Login;

use Datto\Config\DeviceConfig;
use Datto\Log\DeviceLoggerInterface;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;

/**
 * Service class to control local login.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class LocalLoginService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const KEY_FILE = 'disableLocalLogin';
    private DeviceConfig $deviceConfig;

    /**
     * @param DeviceConfig $deviceConfig
     */
    public function __construct(DeviceConfig $deviceConfig)
    {
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * Enable local login.
     */
    public function enable()
    {
        $this->logger->info('LLS0010 Enabling local login'); // log code is used by device-web see DWI-2252
        $this->deviceConfig->clear(self::KEY_FILE);
    }

    /**
     * Disable local login.
     */
    public function disable()
    {
        $this->logger->info('LLS0020 Disabling local login'); // log code is used by device-web see DWI-2252
        $this->deviceConfig->setRaw(self::KEY_FILE, '');
    }

    /**
     * Check if local login is enabled.
     *
     * @return bool True if local login is enabled, False otherwise
     */
    public function isEnabled(): bool
    {
        return !$this->deviceConfig->has(self::KEY_FILE);
    }
}
