<?php

namespace Datto\Service\CloudManagedConfig;

use Datto\Cloud\JsonRpcClient;
use Datto\DeviceConfig\Config\DeviceConfig;
use Datto\DeviceConfig\Serializer;
use Datto\DeviceConfig\ToCloudMeta;
use Datto\DeviceConfig\ToCloudPayload;
use Datto\DeviceConfig\ToDeviceMeta;
use Datto\DeviceConfig\ToDevicePayload;
use Datto\Log\LoggerAwareTrait;
use Datto\Service\CloudManagedConfig\Exception\VersionConflict;
use Datto\Service\CloudManagedConfig\Mappers\DeviceConfigMapper;
use Datto\Service\CloudManagedConfig\Result\PullResult;
use Psr\Log\LoggerAwareInterface;
use Throwable;

class CloudManagedConfigService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DEVICE_CONFIG_GET = 'v1/device/config/get';
    const DEVICE_CONFIG_STORE = 'v1/device/config/store';

    private JsonRpcClient $deviceweb;
    private Serializer $serializer;
    private DeviceConfigMapper $deviceConfigMapper;
    private CloudManagedConfigState $cloudManagedConfigState;

    public function __construct(
        JsonRpcClient $deviceweb,
        Serializer $serializer,
        DeviceConfigMapper $deviceConfigMapper,
        CloudManagedConfigState $cloudManagedConfigState
    ) {
        $this->deviceweb = $deviceweb;
        $this->serializer = $serializer;
        $this->deviceConfigMapper = $deviceConfigMapper;
        $this->cloudManagedConfigState = $cloudManagedConfigState;
    }

    public function pullFromCloud(): PullResult
    {
        $this->logger->info('CMC1000 Pulling configs from cloud and applying locally');
        try {
            $payload = $this->pullPayload();
        } catch (Throwable $e) {
            $this->logger->error('CMC1001 An error occurred when pulling configs from the cloud', [
                'exception' => $e
            ]);

            throw $e;
        }

        $pullResult = new PullResult((bool) $payload->getMeta()->hasCloudChanges());

        if ($payload->getMeta()->hasCloudChanges()) {
            $this->logger->debug('CMC1002 Cloud has changes, applying them locally');

            try {
                $this->verify($payload->getMeta());
                $this->deviceConfigMapper->apply($payload->getDeviceConfig());
                $this->cloudManagedConfigState->setUpdatedAt($payload->getMeta()->getUpdatedAt());
                $this->cloudManagedConfigState->setAppliedAt();

                $pullResult->setResult(true);
            } catch (Throwable $e) {
                $this->logger->error('CMC1003 An error occurred when applying configs', [
                    'exception' => $e
                ]);

                $pullResult->setResult(false, $e);
            }
        }

        return $pullResult;
    }

    public function pushToCloud(?PullResult $pullResult = null): void
    {
        $this->logger->info('CMC1004 Collecting local configs and pushing to cloud');
        $deviceConfig = $this->deviceConfigMapper->get();

        if ($pullResult === null) {
            $this->logger->info('CMC1005 Push is being forced, updating timestamps');
            $this->cloudManagedConfigState->setUpdatedAt();
            $this->cloudManagedConfigState->setAppliedAt();
        }

        $payload = new ToCloudPayload(
            $this->generateMeta($deviceConfig, $pullResult),
            $deviceConfig
        );

        $this->pushPayload($payload);
    }

    /**
     * Push formatted configs to the cloud.
     */
    private function pushPayload(ToCloudPayload $payload): void
    {
        $normalizedPayload = $this->serializer->normalizeToCloudPayload($payload);

        $this->logger->info('CMC1006 Pushing config to cloud', [
            'normalizedPayload' => json_encode($normalizedPayload)
        ]);

        $this->pushNormalizedConfigToDeviceweb($normalizedPayload);

        $this->logger->debug('CMC1007 Pushed config to cloud', [
            'normalizedPayload' => json_encode($normalizedPayload)
        ]);
    }

    /**
     * Pull formatted configs from the cloud.
     */
    private function pullPayload(): ToDevicePayload
    {
        $this->logger->info('CMC1008 Pulling config from cloud');

        $result = $this->getNormalizedPayloadFromDeviceweb();

        $this->logger->debug('CMC1009 Pulled config from cloud', [
            'normalizedPayload' => json_encode($result)
        ]);

        return $this->serializer->denormalizeToDevicePayload($result);
    }

    private function verify(ToDeviceMeta $toDeviceMeta): void
    {
        $toCloudMeta = $this->generateMeta($this->deviceConfigMapper->get());
        $deviceVersion = $toCloudMeta->getVersion();
        $cloudVersion = $toDeviceMeta->getVersion();

        if ($deviceVersion !== $cloudVersion) {
            throw new VersionConflict($deviceVersion, $cloudVersion);
        }
    }

    private function generateMeta(DeviceConfig $deviceConfig, ?PullResult $pullResult = null): ToCloudMeta
    {
        $version = sha1($this->serializer->serializeDeviceConfig($deviceConfig));
        $meta = new ToCloudMeta(
            $version,
            $this->cloudManagedConfigState->getUpdatedAt(),
            $this->cloudManagedConfigState->getAppliedAt()
        );

        if ($pullResult !== null) {
            $meta->setCloudChangesAppliedSuccessfully($pullResult->wasSuccessfullyApplied());
            $meta->setCloudChangesAppliedError($pullResult->getErrorMessage());
        }

        return $meta;
    }

    private function getNormalizedPayloadFromDeviceweb(): array
    {
        $result = $this->deviceweb->queryWithId(self::DEVICE_CONFIG_GET);

        if (!is_array($result)) {
            throw new \Exception('When getting cloud config from deviceweb, it returned a non-array result');
        }

        return $result;
    }

    private function pushNormalizedConfigToDeviceweb(array $normalizedPayload): void
    {
        $this->deviceweb->queryWithId(self::DEVICE_CONFIG_STORE, [
            'config' => $normalizedPayload
        ]);
    }
}
