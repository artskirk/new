<?php

namespace Datto\System\Migration;

use Datto\Asset\Serializer\Serializer;
use Datto\App\Console\Command\Migration\RunIfScheduledCommand;
use Datto\App\Console\SnapctlApplication;
use Datto\Common\Resource\ProcessFactory;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\System\Storage\StorageDevice;
use Datto\System\Storage\StorageDeviceFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;

/**
 * Handles reading migrations from disk and running them if necessary.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class MigrationService
{
    const COMPLETED_MIGRATIONS_DIR = "/datto/config/migrations";
    const COMPLETED_MIGRATION_FILENAME_FORMAT = self::COMPLETED_MIGRATIONS_DIR . "/migration-%s.complete";
    const MIGRATION_LOCK_FILE = '/datto/config/migration.lock';
    const MIGRATION_SAVE_FILE = '/datto/config/migration';
    const RAIDZ_PARITY_DRIVE_COUNT = 1;
    const RAIDZ2_PARITY_DRIVE_COUNT = 2;
    const EXPANSION_TYPE_MIRROR = 'mirror';
    const EXPANSION_TYPE_RAIDZ = 'raidz';
    const EXPANSION_TYPE_RAIDZ2 = 'raidz2';
    const MIGRATION_STATUS_SCHEDULED = 'scheduled';
    const MIGRATION_STATUS_RUNNING = 'running';
    const MIGRATION_STATUS_DONE = 'done';
    const MIGRATION_STATUS_ERROR = 'error';
    const MKDIR_MODE = 0777;

    /** @var Serializer */
    private $migrationSerializer;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var Lock */
    private $lock;

    /** @var StorageDeviceFactory */
    private $storageDeviceFactory;

    private ProcessFactory $processFactory;

    /** @var MigrationFactory */
    private $migrationFactory;

    public function __construct(
        Serializer $migrationSerializer,
        Filesystem $filesystem,
        DateTimeService $time,
        DeviceLoggerInterface $logger,
        LockFactory $lockFactory,
        StorageDeviceFactory $storageDeviceFactory,
        ProcessFactory $processFactory,
        MigrationFactory $migrationFactory
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->dateTimeService = $time;
        $this->lock = $lockFactory->getProcessScopedLock(static::MIGRATION_LOCK_FILE);
        $this->storageDeviceFactory = $storageDeviceFactory;
        $this->processFactory = $processFactory;
        $this->migrationFactory = $migrationFactory;
        $this->migrationSerializer = $migrationSerializer;
    }

    /**
     * Will run a migration if one is scheduled and not currently running
     */
    public function runIfScheduled(): bool
    {
        $this->logger->debug("MIG0001 Checking to see if migration is scheduled...");

        if (!$this->attemptAcquireLock()) {
            $this->logger->error('MIG0007 Another migration is currently running, exiting');
            throw new \Exception('Another migration is currently running, exiting');
        }

        $migration = $this->getScheduled();

        if ($migration !== null && $this->isScheduledToRunNow($migration)) {
            $this->logger->debug("MIG0002 Migration file found...");
            $this->logger->debug("MIG0003 Initiating migration process...");

            try {
                $migration->setStatus(self::MIGRATION_STATUS_RUNNING);
                $this->save($migration);

                $migration->run();

                $migration->setStatus(self::MIGRATION_STATUS_DONE);
                $this->save($migration);
            } catch (\Throwable $e) {
                $this->logger->error('MIG0004 Migration failed', ['exception' => $e]);

                $migration->setStatus(self::MIGRATION_STATUS_ERROR);
                $this->save($migration);
                throw new \Exception("Migration failed, see " . static::COMPLETED_MIGRATIONS_DIR . " for more information");
            } finally {
                $this->logger->info("MIG0005 Saving migration to disk...");
                $this->complete($migration);
                $this->lock->unlock();
            }

            $migration->rebootIfNeeded();
            return true;
        }

        $this->logger->debug("MIG0006 Migration not scheduled to run");
        $this->lock->unlock();

        return false;
    }

    /**
     * Runs the snapctl command that checks for a migration and runs if scheduled
     */
    public function runIfScheduledBackground(): bool
    {
        $process = $this->processFactory
            ->get(['snapctl', RunIfScheduledCommand::getDefaultName(), '--' . SnapctlApplication::BACKGROUND_OPTION_NAME]);

        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Check if a migration is currently running.
     *
     * @return bool
     */
    public function isRunning() : bool
    {
        return $this->lock->isLocked();
    }

    /**
     * @param int $scheduledTime
     * @param string[] $sources
     * @param string[] $targets
     * @param bool $enableMaintenanceMode
     * @param MigrationType $type
     * @param bool $runInBackground
     */
    public function schedule(
        int $scheduledTime,
        array $sources,
        array $targets,
        bool $enableMaintenanceMode,
        MigrationType $type,
        bool $runInBackground = true
    ) {
        if (!$this->attemptAcquireLock()) {
            throw new \Exception('Migration is already in progress.');
        }

        try {
            $migration = $this->migrationFactory->createMigrationFromMigrationType($type);
            $migration->validate($sources, $targets);

            $schedule = new \DateTime();
            $schedule->setTimestamp($scheduledTime);

            $migration->setScheduleAt($schedule);
            $migration->setEnableMaintenanceMode($enableMaintenanceMode);
            $migration->setSources($sources);
            $migration->setTargets($targets);
            $migration->setStatus(self::MIGRATION_STATUS_SCHEDULED);

            $this->save($migration);
            $this->logger->info('MIG0008 Scheduled migration', ['scheduledMigration' => $migration->getScheduleAt()->format(DATE_COOKIE)]);
        } finally {
            $this->lock->unlock();
        }

        if ($this->isScheduledToRunNow($migration)) {
            if ($runInBackground) {
                $this->logger->info("MIG0009 Migration is scheduled to run now, executing on the background...");
                $this->runIfScheduledBackground();
            } else {
                $this->logger->info("MIG0019 Migration is scheduled to run now, executing ...");
                $this->runIfScheduled();
            }
        }
    }

    /**
     * @param string[] $sources
     * @param string[] $targets
     * @param MigrationType $type
     */
    public function validate(array $sources, array $targets, MigrationType $type)
    {
        $migration = $this->migrationFactory->createMigrationFromMigrationType($type);
        $migration->validate($sources, $targets);
    }

    /**
     * Removes migration file if found and migration is not running
     */
    public function cancelScheduled()
    {
        $this->logger->debug("MIG0010 Trying to remove scheduled migration...");

        if (!$this->attemptAcquireLock()) {
            $this->logger->error('MIG0011 Migration currently running, will not remove file');
            throw new \Exception('Migration currently running, will not remove file');
        }

        if ($this->filesystem->exists(static::MIGRATION_SAVE_FILE)) {
            $this->filesystem->unlink(static::MIGRATION_SAVE_FILE);
            $this->logger->info("MIG0012 migration removed correctly.");
        } else {
            $this->logger->error("MIG0013 No migration file to remove");
        }

        $this->lock->unlock();
    }

    /**
     * Given an array of drives, calculate all possible expansion methods and sizes
     *
     * @param string[] $devices
     * @return int[]
     */
    public function calculateAllPossibleExpansionSizes(array $devices): array
    {
        $deviceCount = count($devices);
        $expansionSizes = [];

        if ($deviceCount >= 2) {
            $expansionSizes[static::EXPANSION_TYPE_MIRROR] =
                $this->calculateExpansionSize($devices, static::EXPANSION_TYPE_MIRROR);
        }
        if ($deviceCount >= 3) {
            $expansionSizes[static::EXPANSION_TYPE_RAIDZ] =
                $this->calculateExpansionSize($devices, static::EXPANSION_TYPE_RAIDZ);
        }
        if ($deviceCount >= 4) {
            $expansionSizes[static::EXPANSION_TYPE_RAIDZ2] =
                $this->calculateExpansionSize($devices, static::EXPANSION_TYPE_RAIDZ2);
        }

        return $expansionSizes;
    }

    /**
     * Calculates additional size based on mirror/raidZ/raidZ2
     * Returns size in bytes
     *
     * @param array $devices
     * @param string $migrationType
     * @return int
     */
    public function calculateExpansionSize(array $devices, string $migrationType): int
    {
        $storageDevices = [];
        foreach ($devices as $device) {
            $storageDevices[] = $this->storageDeviceFactory->getStorageDevice($device);
        }

        switch ($migrationType) {
            case self::EXPANSION_TYPE_MIRROR:
                $expansionSize = $this->calculateMirrorExpansionSize($storageDevices);
                break;
            case self::EXPANSION_TYPE_RAIDZ:
                $expansionSize = $this->calculateRaidZExpansionSize($storageDevices);
                break;
            case self::EXPANSION_TYPE_RAIDZ2:
                $expansionSize = $this->calculateRaidZ2ExpansionSize($storageDevices);
                break;
            default:
                throw new \Exception("Unable to calculate expansion size for type [ " . $migrationType . " ]");
        }

        return $expansionSize;
    }

    /**
     * Reads the migration object from the filesystem and unserializes it
     *
     * @return AbstractMigration|null The scheduled migration
     */
    public function getScheduled()
    {
        if ($this->filesystem->exists(static::MIGRATION_SAVE_FILE)) {
            return $this->readAndUnserialize(static::MIGRATION_SAVE_FILE);
        }

        return null;
    }

    /**
     * Get the latest completed migration (note: a completed migration may have been a success or a failure).
     *
     * @return AbstractMigration|null The latest completed migration
     */
    public function getLatestCompleted()
    {
        $completedMigrationFiles = $this->getCompletedFiles();
        rsort($completedMigrationFiles);

        $latestCompletedMigrationFile = $completedMigrationFiles[0] ?? null;
        if (!$latestCompletedMigrationFile) {
            return null;
        }

        return $this->readAndUnserialize($latestCompletedMigrationFile);
    }

    /**
     * Get all completed migrations in the order they were completed
     *
     * @return array Array of completed migrations
     */
    public function getAllCompleted()
    {
        $completedMigrations = array();
        $completedMigrationFiles = $this->getCompletedFiles();
        rsort($completedMigrationFiles);

        foreach ($completedMigrationFiles as $file) {
            $migration = $this->readAndUnserialize($file);
            if ($migration !== null) {
                $completedMigrations[] = $migration;
            }
        }

        return $completedMigrations;
    }

    /**
     * Dismiss completed migrations so that they are not displayed as banners.
     */
    public function dismissAllCompleted()
    {
        $completedMigrationFiles = $this->getCompletedFiles();

        foreach ($completedMigrationFiles as $completedMigrationFile) {
            $migration = $this->readAndUnserialize($completedMigrationFile);

            if ($migration !== null) {
                $migration->setDismissed(true);
                $this->saveCompleted($migration);
            }
        }
    }

    /**
     * Dismiss a specific completed migration using the schedule timestamp as an identifier
     *
     * Note: The timestamp can currently be used as an identifier because you can only have 1 scheduled migration at a
     * time. This means that it should be impossible to have two completed migrations with the same timestamp
     *
     * @param int $timestamp Unix timestamp identifying the completed migration
     *
     * @return bool Whether or not the migration was dismissed
     */
    public function dismissCompletedMigration(int $timestamp): bool
    {
        $migrations = $this->getAllCompleted();
        foreach ($migrations as $migration) {
            if ($migration->getScheduleAt()->getTimestamp() === $timestamp) {
                $migration->setDismissed(true);
                $this->saveCompleted($migration);

                return true;
            }
        }

        return false;
    }

    /**
     * Get all of the migrations, both scheduled and completed
     * @return array The list of migrations, scheduled first then completed
     */
    public function getAllMigrations(): array
    {
        $scheduled = $this->getScheduled();
        $allMigrations = $this->getAllCompleted();
        if ($scheduled) {
            $allMigrations[] = $scheduled;
        }

        return $allMigrations;
    }

    /**
     * Helper for reading in a migration file and unserializing it.
     *
     * @param string $migrationFile
     * @return AbstractMigration|null
     */
    private function readAndUnserialize($migrationFile)
    {
        if (!$this->filesystem->exists($migrationFile)) {
            throw new \Exception('Migration file does not exist: ' . $migrationFile);
        }

        $serializedMigration = $this->filesystem->fileGetContents($migrationFile);
        return $this->migrationSerializer->unserialize($serializedMigration);
    }

    /**
     *
     * @param array $devices
     * @return int
     */
    private function calculateMirrorExpansionSize(array $devices): int
    {
        $sizes = array_map(
            function (StorageDevice $device) {
                return $device->getCapacity();
            },
            $devices
        );

        return min($sizes);
    }

    /**
     * @param array $devices
     * @return int
     */
    private function calculateRaidZExpansionSize(array $devices): int
    {
        return $this->calculateRaidTypeExpansionSize($devices, static::RAIDZ_PARITY_DRIVE_COUNT);
    }

    /**
     * @param array $devices
     * @return int
     */
    private function calculateRaidZ2ExpansionSize(array $devices): int
    {
        return $this->calculateRaidTypeExpansionSize($devices, static::RAIDZ2_PARITY_DRIVE_COUNT);
    }

    /**
     * @param array $devices
     * @param int $parityCount
     * @return int
     */
    private function calculateRaidTypeExpansionSize(array $devices, int $parityCount): int
    {
        $sizes = array_map(
            function (StorageDevice $device) {
                return $device->getCapacity();
            },
            $devices
        );

        $minSize = min($sizes);

        $driveCount = count($devices);

        return ($driveCount - $parityCount) * $minSize;
    }

    /**
     * Write the Migration to disk and let the cron pick it up when it's scheduled.
     *
     * @param AbstractMigration $migration
     */
    private function save(AbstractMigration $migration)
    {
        $this->filesystem->filePutContents(static::MIGRATION_SAVE_FILE, $this->migrationSerializer->serialize($migration));
    }

    /**
     * Checks whether or not a migration is scheduled to run now
     *
     * @param AbstractMigration $migration
     * @return bool
     */
    private function isScheduledToRunNow(AbstractMigration $migration): bool
    {
        $now = $this->dateTimeService->getTime();

        $shouldRun = $now >= $migration->getScheduleAt()->getTimestamp();
        if ($shouldRun) {
            $this->logger->debug("MIG0014 Migration has to be initiated now");
        } else {
            $this->logger->debug("MIG0015 Migration does not have to be initiated yet");
        }

        return $shouldRun;
    }

    /**
     * Attempts to acquire migration lock. If it returns true it means that the lock was acquired
     *
     * @return bool
     */
    private function attemptAcquireLock(): bool
    {
        $acquired = $this->lock->exclusive(false);

        if ($acquired) {
            $this->logger->debug("MIG0016 No migration in progress, lock acquired");
        } else {
            $this->logger->debug("MIG0018 Migration in progress, lock was not acquired");
        }

        return $acquired;
    }

    /**
     * Move the migration object to the completion directory.
     *
     * @param AbstractMigration $migration
     */
    private function complete(AbstractMigration $migration)
    {
        $this->save($migration);

        if (!$this->filesystem->isDir(static::COMPLETED_MIGRATIONS_DIR)) {
            $this->filesystem->mkdir(static::COMPLETED_MIGRATIONS_DIR, false, self::MKDIR_MODE);
        }

        $fileName = sprintf(static::COMPLETED_MIGRATION_FILENAME_FORMAT, $migration->getScheduleAt()->getTimestamp());
        $this->filesystem->rename(static::MIGRATION_SAVE_FILE, $fileName);
        $this->logger->info("MIG0017 Completed migration", ['migrationSaveFile' => $fileName]);
    }

    /**
     * Save a completed migration.
     *
     * @param AbstractMigration $migration
     */
    private function saveCompleted(AbstractMigration $migration)
    {
        if (!$this->filesystem->isDir(static::COMPLETED_MIGRATIONS_DIR)) {
            $this->filesystem->mkdir(static::COMPLETED_MIGRATIONS_DIR, false, self::MKDIR_MODE);
        }

        $fileName = sprintf(static::COMPLETED_MIGRATION_FILENAME_FORMAT, $migration->getScheduleAt()->getTimestamp());
        $this->filesystem->filePutContents($fileName, $this->migrationSerializer->serialize($migration));
    }

    /**
     * Get all completed migration files.
     *
     * @return array
     */
    private function getCompletedFiles()
    {
        return $this->filesystem->glob(sprintf(static::COMPLETED_MIGRATION_FILENAME_FORMAT, "*"));
    }
}
