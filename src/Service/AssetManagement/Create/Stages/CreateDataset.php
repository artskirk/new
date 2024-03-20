<?php

namespace Datto\Service\AssetManagement\Create\Stages;

use Datto\Asset\AssetUuidService;
use Datto\Config\AgentStateFactory;
use Datto\Service\AssetManagement\Create\CreateAgentProgress;
use Datto\ZFS\ZfsDatasetService;

/**
 * Responsible for creating the agent zfs dataset
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class CreateDataset extends AbstractCreateStage
{
    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var AgentStateFactory */
    private $agentStateFactory;

    /** @var bool */
    private $didCreateZfsDataset;

    public function __construct(ZfsDatasetService $zfsDatasetService, AgentStateFactory $agentStateFactory)
    {
        $this->zfsDatasetService = $zfsDatasetService;
        $this->agentStateFactory = $agentStateFactory;
        $this->didCreateZfsDataset = false;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        $agentState = $this->agentStateFactory->create($this->context->getAgentKeyName());
        $createProgress = new CreateAgentProgress();
        $createProgress->setState(CreateAgentProgress::DEFAULTS);
        $agentState->saveRecord($createProgress);

        // todo avoid ZfsDatasetService. It requires zfs list for basically every operation
        $dataset = ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET . '/' . $this->context->getAgentKeyName();
        if (!$this->zfsDatasetService->exists($dataset)) {
            $properties = [AssetUuidService::ZFS_DATTO_UUID_PROPERTY => $this->context->getUuid()];
            $this->zfsDatasetService->createDataset($dataset, true, $properties);
            $this->didCreateZfsDataset = true;
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        // none
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        $logger = $this->context->getLogger();
        $datasetName = ZfsDatasetService::HOMEPOOL_HOME_AGENTS_DATASET . '/' . $this->context->getAgentKeyName();
        $dataset = $this->zfsDatasetService->findDataset($datasetName);

        // Only destroy dataset if we created it in this transaction
        if ($this->didCreateZfsDataset && $dataset) {
            $logger->info('PAR0302 Destroying zfs dataset', ['datasetName' => $datasetName]);
            $this->zfsDatasetService->destroyDataset($dataset);
        } else {
            $logger->info('PAR0303 Skip destroying zfs dataset because we didn\'t create it.', ['datasetName' => $datasetName]);
        }
    }
}
