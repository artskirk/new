<?php

namespace Datto\System\Transaction;

use DateTimeInterface;
use Datto\Backup\BackupException;
use Datto\Resource\DateTimeService;
use Datto\Log\DeviceLoggerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;

/**
 * A transaction is a mechanism to execute a process of multiple stages, and
 * roll them back if any of the stages fails.
 *
 * Generates log messages with the TRN prefix in the range 0000-0100, if a logger interface is passed into the object.
 *
 * @author Philipp Heckel <pheckel@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class Transaction
{
    /** @var Stage[] */
    private $stages;

    /** @var callable */
    private $onCancelCallback;

    /** @var callable */
    private $onCancelFinishedCallback;

    /** @var Stage[] */
    private $committedStages;

    /** @var TransactionFailureType */
    private $failureType;

    /** @var mixed|null */
    protected $context;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DateTimeInterface */
    private $startTime;

    /** @var DateTimeInterface */
    private $endTime;

    /**
     * Create a transaction object with an
     * empty stages array.
     *
     * @param TransactionFailureType $failureType Determines how stage failures are handled.
     * @param DeviceLoggerInterface $logger
     * @param mixed|null $context The context object for the transaction or null if there isn't one
     * @param DateTimeService|null $dateTimeService
     */
    public function __construct(
        TransactionFailureType $failureType = null,
        DeviceLoggerInterface $logger = null,
        $context = null,
        DateTimeService $dateTimeService = null
    ) {
        $this->stages = [];
        $this->onCancelCallback = null;
        $this->onCancelFinishedCallback = null;
        $this->committedStages = [];
        $this->failureType = $failureType ?: TransactionFailureType::STOP_ON_FAILURE();
        $this->logger = $logger;
        $this->context = $context;
        $this->dateTimeService = $dateTimeService ?? new DateTimeService();
    }

    /**
     * Add a new stage to the transaction.
     *
     * Note that this will NOT execute the stage. It is merely
     * added to a list. To execute, run commit().
     *
     * @param Stage $stage
     * @return Transaction Returns an instance of itself (this transaction).
     */
    public function add(Stage $stage)
    {
        $stage->setContext($this->context);
        $this->stages[] = $stage;
        return $this;
    }

    /**
     * Add a new stage to the transaction if the given condition is true.
     *
     * Note that this will NOT execute the stage. It is merely
     * added to a list. To execute, run commit().
     *
     * @param bool $condition Condition to be tested
     * @param Stage $stage Stage to be added
     * @param Stage $alternativeStage optional stage to add if consition is not met
     * @return Transaction Returns an instance of itself (this transaction).
     */
    public function addIf(bool $condition, Stage $stage, Stage $alternativeStage = null)
    {
        if ($condition) {
            $this->add($stage);
        } elseif ($alternativeStage !== null) {
            $this->add($alternativeStage);
        }

        return $this;
    }

    /**
     * Set an optional cancel callback function.
     * This callback will be called before each stage in the transaction.
     * The callback should return a bool with true indicating that the transaction should be cancelled.
     *
     * @param callable $onCancelCallback Callback to determine if the transaction should be cancelled.
     * @param callable $onCancelFinishedCallback (optional) Callback to cleanup, if a transaction has been cancelled.
     * @return Transaction Returns an instance of itself (this transaction).
     */
    public function setOnCancelCallback(
        callable $onCancelCallback,
        callable $onCancelFinishedCallback = null
    ): self {
        $this->onCancelCallback = $onCancelCallback;
        $this->onCancelFinishedCallback = $onCancelFinishedCallback;
        return $this;
    }

    /**
     * @return array|Stage[] Stages that are part of the transaction
     */
    public function getStages()
    {
        return $this->stages;
    }

    /**
     * @return array|Stage[] Stages that were committed part of the transaction
     */
    public function getCommittedStages()
    {
        return $this->committedStages;
    }

    /**
     * Execute all stages in the stages array.
     *
     * If the transaction is set to stop on failure, all committed stages are rolled back,
     * including the stage that failed. Also, a TransactionException will be thrown to halt transaction processing.
     *
     * If the transaction is set to continue on failure, only the current stage is rolled back.
     * The transaction will continue to execute the remaining stages.
     *
     * Regardless of the outcome, the stages will remain in the list. They
     * have to be manually cleared.
     *
     * @return Transaction Returns an instance of itself (this transaction).
     */
    public function commit()
    {
        $this->startTime = $this->dateTimeService->now();

        foreach ($this->stages as $stage) {
            $stageName = $this->getName($stage);
            try {
                $onCancelCallback = $this->onCancelCallback;
                if (isset($onCancelCallback) &&
                    is_callable($onCancelCallback) &&
                    $onCancelCallback()) {
                    $this->handleCancel();
                }

                if (isset($this->logger)) {
                    $this->logger->debug('TRN0000 Committing transaction stage', ['stage' => $stageName]);
                }

                array_unshift($this->committedStages, $stage);
                $this->commitStage($stage);

                if (isset($this->logger)) {
                    $this->logger->debug('TRN0001 Finished transaction stage', ['stage' => $stageName]);
                }
            } catch (\Throwable $e) {
                $context = ($e instanceof BackupException) ? $e->getContext() : [];
                if (isset($this->logger)) {
                    $this->logger->error('TRN0002 Failed to complete transaction stage', array_merge(['stage' => $stageName, 'exception' => $e], $context));
                }
                $this->handleStageFailure($stage, $e);
            }
        }

        $this->cleanup();

        $this->endTime = $this->dateTimeService->now();

        return $this;
    }

    /**
     * Removes all stages from the list.
     * Removes the cancel callback function.
     *
     * @return Transaction Returns an instance of itself (this transaction).
     */
    public function clear()
    {
        $this->stages = [];
        $this->onCancelCallback = null;
        $this->onCancelFinishedCallback = null;
        return $this;
    }

    /**
     * @param LoggerInterface $logger
     * @return Transaction
     */
    public function setLogger(LoggerInterface $logger)
    {
        if (!($logger instanceof DeviceLoggerInterface)) {
            throw new InvalidTypeException('setLogger expected type ' . DeviceLoggerInterface::class . ', received type ' . gettype($logger));
        }
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return DateTimeInterface
     */
    public function getStartTime(): DateTimeInterface
    {
        return $this->startTime;
    }

    /**
     * @return DateTimeInterface
     */
    public function getEndTime(): DateTimeInterface
    {
        return $this->endTime;
    }

    /**
     * Handles the cancellation of the transaction.
     */
    private function handleCancel()
    {
        $this->logger->info('TRN0011 Transaction has been cancelled.');

        $onCancelFinishedCallback = $this->onCancelFinishedCallback;
        if (isset($onCancelFinishedCallback) &&
            is_callable($onCancelFinishedCallback)) {
            $onCancelFinishedCallback();
        }

        throw new TransactionException('Transaction cancelled');
    }

    /**
     * Handles the stage failure process.
     * If the transaction is set to stop on failure, all committed stages are rolled back,
     * including the stage that failed. Also, a TransactionException will be thrown to halt transaction processing.
     *
     * If the transaction is set to continue on failure, only the current stage is rolled back.
     * The transaction will continue to execute the remaining stages.
     *
     * @param Stage $stage Current stage that failed.
     * @param \Throwable $throwable Exception thrown.
     */
    private function handleStageFailure(
        Stage $stage,
        \Throwable $throwable
    ) {
        if ($this->failureType === TransactionFailureType::STOP_ON_FAILURE()) {
            if (isset($this->logger)) {
                $this->logger->debug('TRN0009 Transaction failed. Rolling back ... ', ['exception' => $throwable]);
            }
            $this->rollback();

            $this->endTime = $this->dateTimeService->now();

            throw new TransactionException(
                'Transaction failed. Rolled back, ' . $throwable->getMessage(),
                $throwable->getCode(),
                $throwable
            );
        } elseif ($this->failureType === TransactionFailureType::CONTINUE_ON_FAILURE()) {
            if (isset($this->logger)) {
                $this->logger->debug('TRN0010 Stage failed. Rolling back stage ... ', ['exception' => $throwable]);
            }
            $stage->rollback();
        }
    }

    /**
     * Commit a Stage. This function provides a hook for the subclass to add additional logic around individual Stage
     * commitment.
     *
     * @param Stage $stage
     */
    protected function commitStage(Stage $stage)
    {
        $stage->commit();
    }

    /**
     * Clean up the committed stages.
     */
    protected function cleanup()
    {
        foreach ($this->committedStages as $stage) {
            $stageName = $this->getName($stage);
            try {
                if (isset($this->logger)) {
                    $this->logger->debug('TRN0003 Cleaning up stage', ['stageName' => $stageName]);
                }

                $stage->cleanup();

                if (isset($this->logger)) {
                    $this->logger->debug('TRN0004 Successfully cleaned up stage', ['stageName' => $stageName]);
                }
            } catch (\Throwable $e) {
                // Log and continue
                if (isset($this->logger)) {
                    $this->logger->warning('TRN0005 Failed to clean up stage', ['stageName' => $stageName, 'exception' => $e]);
                }
            }
        }
    }

    /**
     * Rollback the committed stages.
     */
    protected function rollback()
    {
        foreach ($this->committedStages as $stage) {
            $stageName = $this->getName($stage);
            try {
                if (isset($this->logger)) {
                    $this->logger->info('TRN0006 Rolling back stage', ['stageName' => $stageName]);
                }

                $stage->rollback();

                if (isset($this->logger)) {
                    $this->logger->info('TRN0007 Successfully rolled back stage', ['stageName' => $stageName]);
                }
            } catch (\Throwable $e) {
                // Log and continue
                if (isset($this->logger)) {
                    $this->logger->warning('TRN0008 Failed to roll back stage', ['stageName' => $stageName, 'exception' => $e]);
                }
            }
        }
    }

    /**
     * Get the class name
     *
     * @param Stage $stage
     *
     * @return string
     *   The class name without the namespace.
     */
    private function getName(Stage $stage): string
    {
        $nameSpacedName = get_class($stage);
        $lastSlash = strrpos($nameSpacedName, '\\');
        $className = substr($nameSpacedName, $lastSlash + 1);
        return $className;
    }
}
