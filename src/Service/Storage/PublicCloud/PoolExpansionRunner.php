<?php

namespace Datto\Service\Storage\PublicCloud;

use Datto\Cloud\JsonRpcClient;
use Datto\Core\Storage\SirisStorage;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\System\Storage\AzureStorageDevice;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Utility\Azure\InstanceMetadata;
use Datto\Utility\Azure\InstanceMetadataDisk;
use Psr\Log\LoggerAwareInterface;

/**
 * Class responsible for initiating an expansion operation.
 *
 * @author Dan Hentschel <dhentschel@datto.com>
 */
class PoolExpansionRunner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const FAILURE_BACKOFF_SECONDS = 60 * 60;
    private const DEVICE_EXPAND_POOL_ENDPOINT = 'v1/device/storage/expansion/expandPool';

    private StorageInterface $storage;
    private SirisStorage $sirisStorage;
    private StorageService $storageService;
    private PoolExpansionStateManager $poolExpansionStateManager;
    private InstanceMetadata $instanceMetadataService;
    private JsonRpcClient $deviceWeb;
    private DateTimeService $dateTimeService;

    public function __construct(
        StorageInterface $storage,
        SirisStorage $sirisStorage,
        StorageService $storageService,
        PoolExpansionStateManager $poolExpansionStateManager,
        InstanceMetadata $instanceMetadataService,
        JsonRpcClient $deviceWeb,
        DateTimeService $dateTimeService
    ) {
        $this->storage = $storage;
        $this->sirisStorage = $sirisStorage;
        $this->storageService = $storageService;
        $this->poolExpansionStateManager = $poolExpansionStateManager;
        $this->instanceMetadataService = $instanceMetadataService;
        $this->deviceWeb = $deviceWeb;
        $this->dateTimeService = $dateTimeService;
    }

    public function conditionallyStartExpansionOperation(int $threshold)
    {
        $storageId = $this->sirisStorage->getStorageId(SirisStorage::HOME_STORAGE, StorageType::STORAGE_TYPE_FILE);
        $info = $this->storage->getStorageInfo($storageId);

        $freeSpace = $info->getFreeSpaceInBytes();
        $usedSpace = $info->getAllocatedSizeInBytes();
        $totalPoolSize = $freeSpace + $usedSpace;
        $percentUsed = $usedSpace * 100 / $totalPoolSize;

        $state = $this->poolExpansionStateManager->getPoolExpansionState();

        $this->logger->debug(
            'PER0000 Pool expansion check requested.',
            [
                'threshold' => $threshold,
                'poolFree' => $freeSpace,
                'poolUsed' => $usedSpace,
                'poolUtilization' => sprintf('%0.1f%%', $percentUsed),
                'state' => $state
            ]
        );

        if ($percentUsed < $threshold) {
            return;
        }

        if ($state->getState() === PoolExpansionState::RUNNING) {
            $this->logger->debug('PER0001 Expansion is already running.');
            return;
        }

        if ($this->inBackoffPeriod($state)) {
            $this->logger->debug('PER0002 Previous expansion failed. Waiting for backoff period to elapse.');
            return;
        }

        if ($state->getState() !== PoolExpansionState::FAILED && $state->getState() !== PoolExpansionState::SUCCESS) {
            $this->logger->error('PER0003 Unrecognized pool expansion state.', ['state' => $state->getState()]);
            throw new \RuntimeException('Unrecognized pool expansion state: ' . $state->getState());
        }

        $this->startExpansionOperationNow();
    }

    public function forceStartExpansionOperation()
    {
        $this->startExpansionOperationNow();
    }

    private function inBackoffPeriod(PoolExpansionState $state)
    {
        return $state->getState() === PoolExpansionState::FAILED &&
            $state->getStateChangedAt() + self::FAILURE_BACKOFF_SECONDS > $this->dateTimeService->getTime();
    }

    private function startExpansionOperationNow()
    {
        $this->logger->info('PER0004 Starting pool expansion operation.');

        try {
            $this->poolExpansionStateManager->setRunning();
            $arguments = $this->getDeviceWebArguments();
            $success = $this->deviceWeb->queryWithId(self::DEVICE_EXPAND_POOL_ENDPOINT, $arguments);

            if (!$success) {
                $this->logger->error('PER0005 Call to device-web returned false.');
                $this->poolExpansionStateManager->setFailed();
            }
        } catch (\Throwable $e) {
            $this->logger->error('PER0006 Call to device-web failed.', ['exception' => $e]);
            $this->poolExpansionStateManager->setFailed();
        }
    }

    private function getDeviceWebArguments(): array
    {
        $imdsData = $this->instanceMetadataService->get();
        $computeData = $imdsData[InstanceMetadata::FIELD_COMPUTE];

        return [
            'subscriptionId' => $computeData[InstanceMetadata::FIELD_SUBSCRIPTION_ID],
            'resourceGroupName' => $computeData[InstanceMetadata::FIELD_RESOURCE_GROUP_NAME],
            'sku' => $computeData[InstanceMetadata::FIELD_VM_SIZE],
            'dataDisks' => $this->getDataDisksArgument()
        ];
    }

    private function getDataDisksArgument(): array
    {
        $dataDisks = $this->storageService->getAzureDataDisks([
            StorageDevice::STATUS_AVAILABLE,
            StorageDevice::STATUS_POOL
        ]);

        return array_map(
            function (AzureStorageDevice $device) {
                return [
                    'name' => $device->getAzureManagedDeviceName(),
                    'sizeGb' => (int) round($device->getCapacity() / InstanceMetadataDisk::BYTES_PER_GB),
                    'lun' => $device->getLunId(),
                    'isInPool' => $device->getStatus() === StorageDevice::STATUS_POOL
                ];
            },
            $dataDisks
        );
    }
}
