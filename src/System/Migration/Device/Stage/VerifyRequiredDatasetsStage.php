<?php

namespace Datto\System\Migration\Device\Stage;

use Datto\Config\DeviceConfig;
use Datto\System\Migration\Stage\AbstractMigrationStage;
use Datto\System\Migration\Context;
use Datto\ZFS\ZfsDatasetFactory;
use Datto\ZFS\ZfsDatasetService;
use Datto\Log\DeviceLoggerInterface;

/**
 * Verify Required dataset (homePool/home/agents) exists and is mounted
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class VerifyRequiredDatasetsStage extends AbstractMigrationStage
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var DeviceConfig */
    private $deviceConfig;

    /** @var ZfsDatasetFactory */
    private $zfsDatasetFactory;

    public function __construct(
        Context $context,
        DeviceLoggerInterface $logger,
        ZfsDatasetService $zfsDatasetService,
        DeviceConfig $deviceConfig,
        ZfsDatasetFactory $zfsDatasetFactory
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->deviceConfig = $deviceConfig;
        $this->zfsDatasetFactory = $zfsDatasetFactory;
    }

    /**
     * @inheritdoc
     */
    public function commit()
    {
        if (!$this->deviceConfig->has(DeviceConfig::KEY_IS_SNAPNAS)) {
            foreach ($this->zfsDatasetService->getAllDatasets() as $dataset) {
                if ($dataset->getName() === ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET) {
                    if ($dataset->isMounted()) {
                        return;
                    }
                    $this->zfsDatasetService->repair();
                    return;
                }
            }

            $this->logger->info(
                'ZDS0005 Dataset does not exist, creating it',
                ['dataset' => ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET]
            );

            $dataset = $this->zfsDatasetFactory->makePartialDataset(
                ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET,
                ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET_PATH
            );

            $dataset->create();
        }
    }

    /**
     * @inheritdoc
     */
    public function cleanup()
    {
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
    }
}
