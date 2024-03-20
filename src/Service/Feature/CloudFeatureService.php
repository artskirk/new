<?php

namespace Datto\Service\Feature;

use Datto\Cloud\JsonRpcClient;
use Datto\Config\DeviceState;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Util\RetryAttemptsExhaustedException;
use Datto\Util\RetryHandler;
use Exception;
use Psr\Log\LoggerAwareInterface;

class CloudFeatureService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const FEATURE_BACKUP_ENABLED = 'backupEnabled';
    const FEATURE_VOLUME_MANAGEMENT = 'volumeManagement';

    const RETRY_ATTEMPTS = 10;
    const RETRY_SLEEP = 30;

    const LATEST_RESULT_KEY = 'latestCloudFeatures';

    private JsonRpcClient $client;
    private DeviceState $deviceState;
    private RetryHandler $retryHandler;
    private Collector $collector;

    public function __construct(
        JsonRpcClient $client,
        DeviceState $deviceState,
        RetryHandler $retryHandler
    ) {
        $this->client = $client;
        $this->deviceState = $deviceState;
        $this->retryHandler = $retryHandler;
    }

    public function isSupported(string $featureName): bool
    {
        $features = $this->getAll();

        return in_array($featureName, $features);
    }

    public function getAll(): array
    {
        // attempt to get features from cache, if we get a valid value back return it
        $features = $this->getFeaturesFromCache();
        if ($features !== null) {
            return $features;
        }

        // cache is did not have a valid value, refresh it, and attempt to get it from cache again
        $this->refresh();
        $features = $this->getFeaturesFromCache();
        if ($features !== null) {
            return $features;
        }

        // cache is invalid and refresh must have fail
        throw new Exception('Could not get cloud features');
    }

    public function refresh(): void
    {
        $this->logger->info('CFS0001 Refreshing cloud features');

        $features = $this->getFeaturesFromDeviceWeb();

        $this->logger->debug('CFS0002 Received cloud features', [
            'features' => $features
        ]);

        if ($features === null) {
            $this->logger->warning('CFS0003 Failed to query cloud features');

            return;
        }

        $this->storeFeaturesInCache($features);
    }

    private function getFeaturesFromDeviceWeb(): ?array
    {
        $features = null;

        try {
            $result = $this->retryHandler->executeAllowRetry(
                function () {
                    return $this->client->queryWithId('v1/device/feature/getAll');
                },
                self::RETRY_ATTEMPTS,
                self::RETRY_SLEEP
            );

            if ($this->isValidFeatures($result)) {
                $features = $result;
            }
        } catch (RetryAttemptsExhaustedException $e) {
            // logged in RetryHandler
        }

        return $features;
    }

    private function getFeaturesFromCache(): ?array
    {
        $features = null;

        $result = $this->readCache();
        if ($this->isValidFeatures($result)) {
            $features = $result;
        }

        return $features;
    }

    private function isValidFeatures($features): bool
    {
        return is_array($features);
    }

    private function storeFeaturesInCache(array $features)
    {
        $this->deviceState->set(self::LATEST_RESULT_KEY, json_encode($features));
    }

    private function readCache()
    {
        return json_decode($this->deviceState->get(self::LATEST_RESULT_KEY), true);
    }
}
