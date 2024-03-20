<?php

namespace Datto\Log;

use Datto\AppKernel;
use Exception;

/**
 * Creates and configures loggers.
 *
 * @author Michael Meyer (mmeyer@datto.com)
 * @author Justin Giacobbi (justin@datto.com)
 * @author Jeffrey Knapp <jknapp@datto.com>
 *
 * @deprecated LoggerFactory should no longer be used.
 * Use LoggerAwareTrait and setter injection instead, see the Logging section in the CodingStyles.md file for details.
 */
class LoggerFactory
{
    /** @var DeviceLoggerInterface */
    private static $logger = null;

    /**
     * Returns a logger appropriate for general/device logs.
     *
     * @return DeviceLoggerInterface
     */
    public static function getDeviceLogger()
    {
        if (!isset(static::$logger)) {
            self::updateStaticLogger();
        }

        return static::$logger;
    }

    /**
     * Returns a logger appropriate for asset (agent/share) specific logs.
     *
     * @param string $assetKey
     * @return DeviceLoggerInterface
     */
    public static function getAssetLogger(string $assetKey)
    {
        if (empty($assetKey)) {
            throw new Exception("Must specify an asset to create an AssetLogger");
        }

        if (!isset(static::$logger)) {
            self::updateStaticLogger();
        }

        if (static::$logger instanceof DeviceLoggerInterface) {
            static::$logger->setAssetContext($assetKey);
        }

        return static::$logger;
    }

    /**
     * Create a device logger instance
     *
     * @return DeviceLoggerInterface
     */
    public function getDevice(): DeviceLoggerInterface
    {
        return self::getDeviceLogger();
    }

    /**
     * Create an asset logger instance
     *
     * @param string $assetKey
     * @return DeviceLoggerInterface
     */
    public function getAsset(string $assetKey): DeviceLoggerInterface
    {
        return self::getAssetLogger($assetKey);
    }

    /**
     * Update this class's static logger with the device logger from the container
     */
    private static function updateStaticLogger()
    {
        if (AppKernel::isRunningUnitTests()) {
            $logger = new DeviceNullLogger();
        } else {
            $container = AppKernel::getBootedInstance()->getContainer();

            if ($container) {
                $logger = $container->get(DeviceLogger::class);
            } else {
                throw new Exception('Unable to get DeviceLogger from container');
            }
        }
        static::$logger = $logger;
    }
}
