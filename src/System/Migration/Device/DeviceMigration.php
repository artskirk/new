<?php

namespace Datto\System\Migration\Device;

use Datto\Metrics\Metrics;
use Datto\Metrics\Collector;
use Datto\System\Migration\AbstractMigration;
use Datto\System\Migration\Context;
use Datto\System\PowerManager;
use Datto\System\Transaction\Transaction;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Creates stages for a device migration
 *
 * @author Jeffrey Knapp <jknapp@datto,com>
 */
class DeviceMigration extends AbstractMigration
{
    const TYPE = "DeviceMigration";

    /** @var DeviceMigrationStagesFactory */
    private $deviceMigrationStagesFactory;

    /** @var PowerManager */
    private $powerManager;

    /** @var Collector */
    private $collector;

    /**
     * @param DeviceLoggerInterface $logger
     * @param Filesystem $filesystem
     * @param Transaction $transaction
     * @param DeviceMigrationStagesFactory $deviceMigrationStagesFactory
     * @param PowerManager $powerManager
     * @param Collector $collector
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Transaction $transaction,
        DeviceMigrationStagesFactory $deviceMigrationStagesFactory,
        PowerManager $powerManager,
        Collector $collector
    ) {
        parent::__construct($logger, $filesystem, $transaction);
        $this->deviceMigrationStagesFactory = $deviceMigrationStagesFactory;
        $this->powerManager = $powerManager;
        $this->collector = $collector;
    }

    /**
     * @inheritdoc
     */
    public function rebootIfNeeded()
    {
        $this->powerManager->rebootDevice();
    }

    /**
     * @inheritdoc
     */
    public function getType() : string
    {
        return static::TYPE;
    }

    /**
     * @inheritdoc
     */
    public function validate(array $sources, array $targets)
    {
        if (count($targets) === 0) {
            throw new Exception('Invalid number of targets. There must be at least 1');
        }
    }

    /**
     * @inheritdoc
     */
    protected function createStages(Context $context) : array
    {
        return $this->deviceMigrationStagesFactory->createStages($context);
    }

    /**
     * Run the Device Migration now, and collect some metrics while doing it
     */
    public function run()
    {
        try {
            $this->collector->increment(Metrics::DEVICE_MIGRATION_STARTED);

            parent::run();

            $this->collector->increment(Metrics::DEVICE_MIGRATION_SUCCESS);
        } catch (Throwable $exception) {
            $this->collector->increment(Metrics::DEVICE_MIGRATION_FAILURE);
            throw $exception;
        }
    }
}
