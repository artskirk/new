<?php

namespace Datto\Events;

use Datto\Config\DeviceConfig;
use Datto\Events\Common\AssetData;
use Datto\Events\Common\CommonEventNodeFactory;
use Datto\Events\Common\PlatformData;
use Datto\Events\Log\LogContext;
use Datto\Events\Log\LogData;
use Datto\Events\Log\LogEventData;
use Datto\Events\Log\LogFlattenStrategy;
use DateTimeInterface;

/**
 * Create an Event that represents a log message
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class LogEventFactory
{
    const IRIS_SOURCE_NAME = 'iris';
    const LOG_EVENT_NAME = 'device.log';

    /** @var CommonEventNodeFactory */
    private $nodeFactory;

    /** @var AssetData[] */
    private $assetDataCache;

    /** @var PlatformData */
    private $platformDataCache;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var string */
    private $deploymentGroupCache;

    public function __construct(CommonEventNodeFactory $nodeFactory, DeviceConfig $deviceConfig)
    {
        $this->nodeFactory = $nodeFactory;
        $this->assetDataCache = [];
        $this->platformDataCache = null;
        $this->deploymentGroupCache = false; // null is a legitimate value; false indicates the cache is not populated.
        $this->deviceConfig = $deviceConfig;
    }

    public function create(
        int $index,
        DateTimeInterface $timestamp,
        string $logLevel,
        string $logCode,
        string $logMessage,
        string $requestId,
        string $userName,
        string $clientIp,
        string $assetKey = null,
        array $context = []
    ): Event {
        $data = new LogEventData(
            $index,
            $this->getPlatformData(),
            new LogData($logLevel, $logCode),
            empty($assetKey) ? null : $this->getAssetData($assetKey)
        );
        $context = new LogContext($logMessage, $userName, $clientIp, $context, $this->getDeploymentGroup());
        $flattenStrategy = new LogFlattenStrategy();

        return new Event(
            self::IRIS_SOURCE_NAME,
            self::LOG_EVENT_NAME,
            $data,
            $context,
            $requestId,
            $this->nodeFactory->getResellerId(),
            $this->nodeFactory->getDeviceId(),
            null,
            $timestamp,
            $flattenStrategy
        );
    }

    private function getAssetData(string $assetKey): AssetData
    {
        if (!array_key_exists($assetKey, $this->assetDataCache)) {
            $this->assetDataCache[$assetKey] = $this->nodeFactory->createAssetData($assetKey);
        }

        return $this->assetDataCache[$assetKey];
    }

    /**
     * This data is not expected to change often (every upgrade at most) so we cache it to avoid unnecessary work
     */
    private function getPlatformData(): PlatformData
    {
        if ($this->platformDataCache === null) {
            $this->platformDataCache = $this->nodeFactory->createPlatformData();
        }

        return $this->platformDataCache;
    }

    /**
     * Get the deployment group that will be shipped with log events if specified.
     *
     * The value is loaded from the key file the first time this method is called and cached for reuse.
     *
     * @return string|null
     */
    private function getDeploymentGroup()
    {
        if ($this->deploymentGroupCache === false) {
            $this->deploymentGroupCache = null;
            $rawDeploymentGroup = $this->deviceConfig->getRaw(DeviceConfig::KEY_DEPLOYMENT_GROUP, null);
            if (is_string($rawDeploymentGroup)) {
                $deploymentGroup = trim($rawDeploymentGroup);
                $this->deploymentGroupCache = (strlen($deploymentGroup) > 0) ? $deploymentGroup : null;
            }
        }
        return $this->deploymentGroupCache;
    }
}
