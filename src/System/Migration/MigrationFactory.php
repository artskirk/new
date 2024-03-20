<?php

namespace Datto\System\Migration;

use Datto\Metrics\Collector;
use Datto\System\Migration\Device\DeviceMigration;
use Datto\System\Migration\Device\DeviceMigrationStagesFactory;
use Datto\System\Migration\ZpoolReplace\ZpoolMigrationValidationService;
use Datto\System\Migration\ZpoolReplace\ZpoolReplaceMigration;
use Datto\System\PowerManager;
use Datto\System\Transaction\Transaction;
use Datto\Common\Utility\Filesystem;
use Exception;
use Datto\Log\DeviceLoggerInterface;

/**
 * Factory to create different types of migrations
 *
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class MigrationFactory
{
    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Filesystem */
    private $filesystem;

    /** @var Transaction */
    private $transaction;

    /** @var ZpoolMigrationValidationService */
    private $zpoolValidationService;

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
     * @param ZpoolMigrationValidationService $zpoolValidationService
     * @param DeviceMigrationStagesFactory $deviceMigrationStagesFactory
     * @param PowerManager $powerManager
     */
    public function __construct(
        DeviceLoggerInterface $logger,
        Filesystem $filesystem,
        Transaction $transaction,
        ZpoolMigrationValidationService $zpoolValidationService,
        DeviceMigrationStagesFactory $deviceMigrationStagesFactory,
        PowerManager $powerManager,
        Collector $collector
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->transaction = $transaction;
        $this->zpoolValidationService = $zpoolValidationService;
        $this->deviceMigrationStagesFactory = $deviceMigrationStagesFactory;
        $this->powerManager = $powerManager;
        $this->collector = $collector;
    }

    /**
     * Create a migration object based on the migration type
     *
     * @param MigrationType $type
     * @return AbstractMigration
     */
    public function createMigrationFromMigrationType(MigrationType $type): AbstractMigration
    {
        switch ($type) {
            case MigrationType::ZPOOL_REPLACE():
                $migration = $this->createZpoolReplaceMigration();
                break;

            case MigrationType::DEVICE():
                $migration = $this->createDeviceMigration();
                break;

            default:
                throw new Exception('Unknown migration type');
        }
        return $migration;
    }

    /**
     * Create a migration object based on the migration type
     *
     * @param string $type
     * @return AbstractMigration
     */
    public function createMigrationFromString(string $type): AbstractMigration
    {
        switch ($type) {
            case ZpoolReplaceMigration::TYPE:
                $migration = $this->createZpoolReplaceMigration();
                break;

            case DeviceMigration::TYPE:
                $migration = $this->createDeviceMigration();
                break;

            default:
                throw new Exception('Unknown migration type');
        }
        return $migration;
    }

    /**
     * Create a zpool replace migration
     *
     * @return ZpoolReplaceMigration
     */
    private function createZpoolReplaceMigration(): ZpoolReplaceMigration
    {
        $migration = new ZpoolReplaceMigration(
            $this->logger,
            $this->filesystem,
            $this->transaction,
            $this->zpoolValidationService
        );
        return $migration;
    }

    /**
     * Create a device migration
     *
     * @return DeviceMigration
     */
    private function createDeviceMigration(): DeviceMigration
    {
        $migration = new DeviceMigration(
            $this->logger,
            $this->filesystem,
            $this->transaction,
            $this->deviceMigrationStagesFactory,
            $this->powerManager,
            $this->collector
        );
        return $migration;
    }
}
