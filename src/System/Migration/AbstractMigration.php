<?php

namespace Datto\System\Migration;

use Datto\System\Transaction\Stage;
use Datto\System\Transaction\Transaction;
use Datto\System\Transaction\TransactionException;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Class that contains fields and common behavior across different migrations.
 *
 * @author Mario Rial <mrial@datto.com>
 */
abstract class AbstractMigration implements \JsonSerializable
{
    const STATUS_UNAVAILABLE = 'unavailable';

    /** @var string[] */
    protected $sources;

    /** @var string[] */
    protected $targets;

    /** @var \DateTime */
    protected $scheduleAt;

    /** @var bool */
    protected $enableMaintenanceMode;

    /** @var string */
    protected $errorMessage;

    /** @var int */
    protected $errorCode;

    /** @var bool */
    protected $dismissed;

    /** @var string */
    protected $status;

    /** @var DeviceLoggerInterface */
    protected $logger;

    /** @var Filesystem */
    protected $filesystem;

    /** @var Transaction */
    protected $transaction;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param Transaction $transaction
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Transaction $transaction
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->transaction = $transaction;
        $this->enableMaintenanceMode = false;
        $this->errorMessage = null;
        $this->dismissed = false;
    }

    /**
     * Get the type of migration.
     *
     * @return string
     */
    abstract public function getType(): string;

    /**
     * Validate that the migration configuration is correct
     *
     * @param string[] $sources
     * @param string[] $targets
     */
    abstract public function validate(array $sources, array $targets);

    /**
     * Reboot the device if the migration requires it
     */
    abstract public function rebootIfNeeded();

    /**
     * Creates all the stages
     *
     * @param Context $context
     * @return Stage[]
     */
    abstract protected function createStages(Context $context): array;

    /**
     * @return string[]
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @param string[] $sources
     * @return self
     */
    public function setSources(array $sources): AbstractMigration
    {
        $this->sources = $sources;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * @param string[] $targets
     * @return self
     */
    public function setTargets(array $targets): AbstractMigration
    {
        $this->targets = $targets;
        return $this;
    }

    /**
     * @return \DateTime
     */
    public function getScheduleAt(): \DateTime
    {
        return $this->scheduleAt;
    }

    /**
     * @param \DateTime $scheduleAt
     * @return self
     */
    public function setScheduleAt(\DateTime $scheduleAt): AbstractMigration
    {
        $this->scheduleAt = $scheduleAt;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasErrorMessage(): bool
    {
        return isset($this->errorMessage);
    }

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage ?: '';
    }

    /**
     * @param string|null $errorMessage
     */
    public function setErrorMessage($errorMessage)
    {
        $this->errorMessage = $errorMessage;
    }

    /**
     * @return int
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * @param int $errorCode
     */
    public function setErrorCode(int $errorCode)
    {
        $this->errorCode = $errorCode;
    }

    /**
     * @return bool
     */
    public function getEnableMaintenanceMode(): bool
    {
        return $this->enableMaintenanceMode;
    }

    /**
     * @param bool $enableMaintenanceMode
     * @return self
     */
    public function setEnableMaintenanceMode(bool $enableMaintenanceMode): AbstractMigration
    {
        $this->enableMaintenanceMode = $enableMaintenanceMode;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDismissed(): bool
    {
        return $this->dismissed;
    }

    /**
     * @param bool $dismissed
     */
    public function setDismissed(bool $dismissed)
    {
        $this->dismissed = $dismissed;
    }

    /**
     * Get the status of the migration
     * @return string The status of the migration
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set the status of the migration
     * @param string $status The status of the migration
     */
    public function setStatus(string $status)
    {
        $this->status = $status;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'sources' => $this->sources,
            'targets' => $this->targets,
            'schedule' => $this->scheduleAt->getTimestamp(),
            'maintenance' => $this->enableMaintenanceMode,
            'error' => $this->errorMessage,
            'errorCode' => $this->errorCode,
            'dismissed' => $this->dismissed,
            'status' => $this->status
        ];
    }

    /**
     * Copy fields from array to this object.
     *
     * @param array $array
     * @return self
     */
    public function populateFromArray(array $array): AbstractMigration
    {
        $schedule = new \Datetime();
        $schedule->setTimestamp($array['schedule']);

        $this->setSources($array['sources']);
        $this->setTargets($array['targets']);
        $this->setScheduleAt($schedule);
        $this->setEnableMaintenanceMode($array['maintenance']);
        $this->setErrorMessage($array['error']);
        $this->setErrorCode(empty($array['errorCode']) ? 0 : $array['errorCode']);
        $this->setDismissed($array['dismissed']);
        $status = $array['status'] ?? self::STATUS_UNAVAILABLE;
        $this->setStatus($status);

        return $this;
    }

    /**
     * Run the Migration now.
     */
    public function run()
    {
        $context = $this->createContext();
        $stages = $this->createStages($context);
        $this->executeStages($stages);
    }

    /**
     * @return Context
     */
    protected function createContext() : Context
    {
        return new Context($this->targets, $this->sources, $this->enableMaintenanceMode);
    }

    /**
     * Execute/commit an array of stages.
     *
     * @param Stage[] $stages
     */
    private function executeStages(array $stages)
    {
        $this->transaction->clear();

        foreach ($stages as $stage) {
            $this->transaction->add($stage);
        }

        try {
            $this->transaction->commit();
            $this->errorMessage = null;
            $this->errorCode = 0;
        } catch (TransactionException $exception) {
            $this->errorMessage = $exception->getPrevious()->getMessage();
            $this->errorCode = $exception->getPrevious()->getCode();
            $this->logger->error('MIG0100 Transaction exception occurred', ['exception' => $exception->getPrevious()]);
            throw $exception->getPrevious();
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();
            $this->errorCode = $exception->getCode();
            $this->logger->error('MIG0101 Stage execution failed', ['exception' => $exception]);
            throw $exception;
        }
    }
}
