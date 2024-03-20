<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Core\Storage\StorageCreationContext;
use Datto\Core\Storage\StorageInterface;
use Datto\Core\Storage\StorageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Utility\Storage\Zpool;
use Datto\ZFS\ZfsDatasetService;
use Throwable;

/**
 * Task to create the agents ZFS dataset on build-imaged devices.
 *
 * This is to prevent a known ZFS race condition: https://kaseya.atlassian.net/browse/BCDR-24863
 * Non-build-imaged devices will have the agents dataset created during registration/auto-activation.
 *   See StorageService::createNewPool()
 */
class CreateAgentDatasetTask implements ConfigRepairTaskInterface
{
    use LoggerAwareTrait;

    const AGENTS_DATASET_NAME = 'agents';

    private StorageInterface $storage;
    private Zpool $zpool;

    public function __construct(StorageInterface $storage, Zpool $zpool)
    {
        $this->storage = $storage;
        $this->zpool = $zpool;
    }

    /** @inheritDoc */
    public function run(): bool
    {
        $properties = ['mountpoint' => ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET_PATH];
        $agentsStorageId = ZfsDatasetService::HOMEPOOL_HOME_DATASET . '/' . self::AGENTS_DATASET_NAME;
        $context = new StorageCreationContext(
            self::AGENTS_DATASET_NAME,
            ZfsDatasetService::HOMEPOOL_HOME_DATASET,
            StorageType::STORAGE_TYPE_FILE,
            StorageCreationContext::MAX_SIZE_DEFAULT,
            false,
            $properties
        );

        try {
            if ($this->zpool->isEnabled() && $this->zpool->exists(ZfsDatasetService::HOMEPOOL_DATASET)) {
                $storageIds = $this->storage->listStorageIds(ZfsDatasetService::HOMEPOOL_DATASET);
                if (!in_array($agentsStorageId, $storageIds)) {
                    $this->storage->createStorage($context);
                    return true;
                }
            }
        } catch (Throwable $throwable) {
            $this->logger->error('ADT0001 Error creating dataset', [
                'storageId' => $agentsStorageId,
                'properties' => $properties
            ]);
        }

        return false;
    }
}
