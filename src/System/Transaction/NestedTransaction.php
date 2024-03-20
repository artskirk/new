<?php

namespace Datto\System\Transaction;

use Datto\Log\DeviceLoggerInterface;

/**
 * A transaction is a mechanism to execute a process of multiple stages, and
 * roll them back if any of the stages fails.
 *
 * A stage is a step in a transaction. It can contain an arbitrary action,
 * but must/should be reversible in the rollback action.
 *
 * This class is a nestable transaction; a transaction that can be executed as a stage within another transaction.
 *
 * @author Stephen Allan <sallan@datto.com>
 */
class NestedTransaction extends Transaction implements Stage
{
    // These variables prevent cleanup/rollback from being called twice. Once when the nested transaction finishes,
    //  and again when the main transaction finishes and calls cleanup on the all of its stages/nested transactions
    // The guard in commit() is not strictly needed, but was added to fit the convention.
    
    /** @var bool */
    private $commitOccurred;

    /** @var bool */
    private $cleanupOccurred;

    /** @var bool */
    private $rollbackOccurred;

    public function __construct(TransactionFailureType $failureType = null, DeviceLoggerInterface $logger = null, $context = null)
    {
        parent::__construct($failureType, $logger, $context);

        $this->commitOccurred = false;
        $this->cleanupOccurred = false;
        $this->rollbackOccurred = false;
    }

    /**
     * @inheritDoc
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * Attempts to execute this stage
     */
    public function commit()
    {
        if (!$this->commitOccurred) {
            parent::commit();
            $this->commitOccurred = true;
        }
    }

    /**
     * Clean up artifacts left behind in the commit stage
     */
    public function cleanup()
    {
        if (!$this->cleanupOccurred) {
            parent::cleanup();
            $this->cleanupOccurred = true;
        }
    }

    /**
     * Rolls back any committed changes
     */
    public function rollback()
    {
        if (!$this->rollbackOccurred) {
            parent::rollback();
            $this->rollbackOccurred = true;
        }
    }
}
