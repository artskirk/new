<?php

namespace Datto\DirectToCloud\Creation\Stages;

use Datto\Asset\Agent\Agent;
use Datto\Asset\AssetUuidService;
use Datto\Config\DeviceConfig;
use Datto\ZFS\ZfsDataset;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Provision storage for an Agent asset.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class ProvisionStorage extends AbstractStage
{
    private ZfsDatasetService $datasetService;
    private DeviceConfig $deviceConfig;

    public function __construct(
        DeviceLoggerInterface $logger,
        ZfsDatasetService $datasetService,
        DeviceConfig $deviceConfig
    ) {
        parent::__construct($logger);
        $this->datasetService = $datasetService;
        $this->deviceConfig = $deviceConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->logger->info("DCS0006 Provisioning storage ...");

        if ($this->context->useExistingDataset()) {
            $dataset = $this->updateStorage();
        } else {
            $dataset = $this->createStorage();
        }

        $this->context->setDataset($dataset);
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
        // Nothing.
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $dataset = $this->context->getDataset();
        if ($dataset) {
            if ($this->context->useExistingDataset()) {
                // Do nothing, because the dataset previously existed. Note: zfs attributes added will not be reverted.
            } else {
                $this->datasetService->destroyDataset($dataset);
            }
        }
    }

    /**
     * Provision new storage of the asset.
     *
     * @return ZfsDataset
     */
    private function createStorage(): ZfsDataset
    {
        $datasetName = sprintf(Agent::ZFS_PATH_TEMPLATE, $this->context->getAssetKey());
        $datasetProperties = [
            AssetUuidService::ZFS_DATTO_UUID_PROPERTY => $this->context->getAssetMetadata()->getAssetUuid()
        ];

        if ($this->datasetService->exists($datasetName)) {
            throw new Exception("Dataset already exists: " . $datasetName);
        }

        return $this->datasetService->createDataset($datasetName, true, $datasetProperties);
    }

    /**
     * Updating existing storage for the asset.
     *
     * @return ZfsDataset
     */
    private function updateStorage(): ZfsDataset
    {
        $datasetName = sprintf(Agent::ZFS_PATH_TEMPLATE, $this->context->getAssetKey());

        $dataset = $this->datasetService->getDataset($datasetName);

        $dataset->setUuid($this->context->getAssetMetadata()->getAssetUuid());

        return $dataset;
    }
}
