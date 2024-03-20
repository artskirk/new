<?php

namespace Datto\Service\Storage\PublicCloud;

use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StoragePoolExpandDeviceContext;
use Datto\Core\Storage\StoragePoolExpansionContext;
use Datto\Log\LoggerAwareTrait;
use Datto\System\Storage\AzureStorageDevice;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageService;
use Datto\Util\RetryHandler;
use Psr\Log\LoggerAwareInterface;

/**
 * Service class for expanding the local data pool.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class PoolExpansionService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const MAXIMUM_FETCH_ATTEMPTS = 10;
    const RETRY_DELAY_SECONDS = 30;

    private StorageService $storageService;
    private StorageInterface $storage;
    private PoolExpansionStateManager $poolExpansionStateManager;
    private RetryHandler $retryHandler;

    public function __construct(
        StorageService $storageService,
        StorageInterface $storage,
        PoolExpansionStateManager $poolExpansionStateManager,
        RetryHandler $retryHandler
    ) {
        $this->storageService = $storageService;
        $this->storage = $storage;
        $this->poolExpansionStateManager = $poolExpansionStateManager;
        $this->retryHandler = $retryHandler;
    }

    /**
     * Adds the specified data disk to the local data pool.
     *
     * @param int $diskLun
     * @param bool $resize
     */
    public function expandPoolIntoDisk(int $diskLun, bool $resize)
    {
        try {
            $this->doExpandPoolIntoDisk($diskLun, $resize);
            $this->poolExpansionStateManager->setSuccess();
        } catch (\Throwable $e) {
            $this->poolExpansionStateManager->setFailed();
            throw $e;
        }
    }

    private function doExpandPoolIntoDisk(int $diskLun, bool $resize): void
    {
        $this->logger->info('PES0011 Starting data pool expansion', [
            'requestedLun' => $diskLun,
            'resize' => $resize
        ]);

        /** @var StorageDevice $poolDisk */
        $poolDisk = $this->retryHandler->executeAllowRetry(
            function () use ($diskLun, $resize) {
                return $this->findDiskByLun($diskLun, $resize);
            },
            self::MAXIMUM_FETCH_ATTEMPTS,
            self::RETRY_DELAY_SECONDS
        );

        $logContext = [
            'requestedLun' => $diskLun,
            'disk' => $poolDisk,
            'resize' => $resize
        ];

        $this->logger->info('PES0012 Attempting data pool expansion with new disk', $logContext);
        $this->retryHandler->executeAllowRetry(
            function () use ($poolDisk, $resize) {
                $pool = StorageService::DEFAULT_POOL_NAME;

                if ($resize) {
                    $expansionContext = new StoragePoolExpandDeviceContext(
                        $pool,
                        $this->getPoolDeviceId($poolDisk)
                    );
                    $this->storageService->rescanDevices();
                    $this->storage->expandPoolDeviceSpace($expansionContext);
                } else {
                    $expansionContext = new StoragePoolExpansionContext(
                        $pool,
                        [$poolDisk->getIds()[0]]
                    );
                    $this->storage->expandPoolSpace($expansionContext);
                }
            }
        );
        $this->logger->info('PES0013 Successfully expanded data pool', $logContext);
    }

    private function getPoolDeviceId(StorageDevice $poolDisk): string
    {
        /**
         * Suppressing this deprecation because it is the current best way to get the ID used in the zpool.
         * @psalm-suppress DeprecatedMethod
         */
        $id = $poolDisk->getId();

        if (empty($id)) {
            throw new \Exception('Could not determine pool device ID');
        }

        return $id;
    }

    private function findDiskByLun(int $diskLun, bool $resize): StorageDevice
    {
        $desiredStatus = $resize ? StorageDevice::STATUS_POOL : StorageDevice::STATUS_AVAILABLE;

        $availableDataDisks = array_values(array_filter(
            $this->storageService->getAzureDataDisks([$desiredStatus]),
            function (StorageDevice $disk) use ($diskLun) {
                return $disk->getLunId() === $diskLun;
            }
        ));

        if (empty($availableDataDisks)) {
            $message = 'Failed to find available disk for pool expansion';
            $this->logger->error("PES0001 $message", ['requestedLun' => $diskLun, 'resize' => $resize]);
            throw new PoolExpansionException($message);
        } elseif (count($availableDataDisks) > 1) {
            $message = 'Found multiple available data disks with the same LUN';
            $this->logger->error("PES0002 $message", [
                'requestedLun' => $diskLun,
                'availableDataDisks' => $availableDataDisks,
                'resize' => $resize
            ]);
            throw new PoolExpansionException($message);
        }

        return $availableDataDisks[0];
    }
}
