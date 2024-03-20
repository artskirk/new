<?php

namespace Datto\App\Controller\Api\V1\Device\Migrate;

use Datto\System\Migration\MigrationService;
use Datto\System\Migration\MigrationType;

/**
 * Endpoint for scheduling migrations
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class Migrations
{
    /** @var MigrationService */
    private $migrationService;

    public function __construct(MigrationService $migrationService)
    {
        $this->migrationService = $migrationService;
    }

    /**
     * Schedules a field upgrade
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MIGRATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *     "scheduledTime" = @Symfony\Component\Validator\Constraints\Regex(pattern = "~^[0-9]+$~"),
     *     "targetDriveIds" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     * @param int $scheduledTime
     * @param string[] $sourceDriveIds
     * @param string[] $targetDriveIds
     * @param bool $enableMaintenanceMode
     * @param string $migrationType
     *
     * @return bool
     */
    public function schedule(
        int $scheduledTime,
        array $sourceDriveIds,
        array $targetDriveIds,
        bool $enableMaintenanceMode = false,
        string $migrationType = MigrationType::ZPOOL_REPLACE
    ): bool {
        $type = $migrationType === MigrationType::DEVICE ?
            MigrationType::DEVICE() :
            MigrationType::ZPOOL_REPLACE();

        $this->migrationService->schedule(
            $scheduledTime,
            $sourceDriveIds,
            $targetDriveIds,
            $enableMaintenanceMode,
            $type
        );

        return true;
    }

    /**
     * Validate migration parameters.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MIGRATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "targetDriveIds" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     * @param string[] $sourceDriveIds
     * @param string[] $targetDriveIds
     * @param string $migrationType
     * @return bool
     */
    public function validate(
        array $sourceDriveIds,
        array $targetDriveIds,
        string $migrationType = MigrationType::ZPOOL_REPLACE
    ): bool {
        $type = $migrationType === MigrationType::DEVICE ?
            MigrationType::DEVICE() :
            MigrationType::ZPOOL_REPLACE();

        $this->migrationService->validate($sourceDriveIds, $targetDriveIds, $type);

        return true;
    }

    /**
     * Calculate the expansion size given drives and raid type
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_UPGRADE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "devices" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     * @param string[] $devices
     * @param string $raidType
     * @return int
     */
    public function getExpansionSize(array $devices, string $raidType): int
    {
        return $this->migrationService->calculateExpansionSize($devices, $raidType);
    }

    /**
     * Calculate all possible expansion sizes given drives
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_STORAGE_UPGRADE")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @Datto\JsonRpc\Validator\Validate(fields={
     *      "devices" = @Symfony\Component\Validator\Constraints\Type(type = "array")
     * })
     * @param string[] $devices
     * @return array
     */
    public function getAllPossibleExpansionSizes(array $devices): array
    {
        return $this->migrationService->calculateAllPossibleExpansionSizes($devices);
    }

    /**
     * Removes a previously scheduled migration.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MIGRATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @return bool
     */
    public function cancelScheduled(): bool
    {
        $this->migrationService->cancelScheduled();

        return true;
    }

    /**
     * Dismiss banners for completed migrations.
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MIGRATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @return bool
     */
    public function dismissAllCompleted(): bool
    {
        $this->migrationService->dismissAllCompleted();

        return true;
    }

    /**
     * Dismiss a singular completed migration
     *
     * @Datto\App\Security\RequiresFeature("FEATURE_MIGRATIONS")
     * @Datto\App\Security\RequiresPermission("PERMISSION_STORAGE_UPGRADE")
     * @param int $timestamp Unix timestamp that the migration was scheduled for
     * @return bool
     */
    public function dismissSingleCompleted(int $timestamp): bool
    {
        $dismissed = $this->migrationService->dismissCompletedMigration($timestamp);

        return $dismissed;
    }
}
