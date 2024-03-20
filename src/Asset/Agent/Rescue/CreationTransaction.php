<?php

namespace Datto\Asset\Agent\Rescue;

use Datto\Asset\Agent\Rescue\Stages\CreationStage;
use Datto\Restore\Virtualization\VirtualizationRestoreTool;
use Datto\System\Transaction\Stage;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionException;
use Datto\System\Transaction\TransactionFailureType;
use Exception;
use Datto\Log\DeviceLoggerInterface;

class CreationTransaction extends Transaction
{
    /** @var VirtualizationRestoreTool */
    private $virtRestoreTool;

    /**
     * @param RescueAgentCreationContext $context
     * @param DeviceLoggerInterface $logger
     * @param VirtualizationRestoreTool $virtRestoreTool
     */
    public function __construct(
        RescueAgentCreationContext $context,
        DeviceLoggerInterface $logger,
        VirtualizationRestoreTool $virtRestoreTool
    ) {
        parent::__construct(TransactionFailureType::STOP_ON_FAILURE(), $logger);
        $this->virtRestoreTool = $virtRestoreTool;
        $this->context = $context;
    }

    /**
     * {@inheritdoc}
     */
    public function add(Stage $stage)
    {
        if (!$stage instanceof CreationStage) {
            throw new Exception('Stages added to this transaction must extend CreationStage');
        }

        return parent::add($stage);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        try {
            parent::commit();
        } catch (TransactionException $e) {
            // The Transaction class purposefully does not do cleanup for a failed STOP_ON_FAILURE transaction. We,
            // however, want to do this cleanup even in the case of failure.
            $this->cleanup();
            throw $e;
        } finally {
            $this->clearStatus();
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function commitStage(Stage $stage)
    {
        $numberOfStages = count($this->getStages());
        $stagesCheckedIn = count($this->getCommittedStages()) - 1; // don't include this stage in the calculation

        try {
            /** @var CreationStage $stage */
            $this->virtRestoreTool->updateVmStatus(
                $this->context->getSourceAgent()->getKeyName(),
                $stagesCheckedIn,
                $numberOfStages,
                $stage->getStatusMessage()
            );
        } catch (Exception $e) {
            $this->logger->error('RSC5001 Unexpected exception creating Rescue Agent', ['exception' => $e]);
        }

        parent::commitStage($stage);
    }



    /**
     * {@inheritdoc}
     */
    protected function cleanup()
    {
        $this->clearStatus();
        parent::cleanup();
    }

    /**
     * Clear the vmStatus file so the ui knows we aren't still trying to create the rescue agent
     */
    private function clearStatus(): void
    {
        try {
            $this->virtRestoreTool->clearVmStatus($this->context->getSourceAgent()->getKeyName());
        } catch (Exception $e) {
            $this->logger->error('RSC5002 Unexpected exception clearing VM status', ['exception' => $e]);
        }
    }
}
