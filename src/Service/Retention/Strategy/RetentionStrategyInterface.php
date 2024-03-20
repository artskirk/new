<?php

namespace Datto\Service\Retention\Strategy;

use Datto\Asset\Asset;
use Datto\Asset\RecoveryPoint\RecoveryPointInfo;
use Datto\Asset\Retention;

/**
 * Defines an interface for helper classes that the RetentionService uses.
 *
 * This is intended to make the RetentionService agnostic to the retention type
 * it's working with because while logic between local and offsite retention is
 * pretty much identical there are subtle differences that the helpers will
 * abstract away.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 */
interface RetentionStrategyInterface
{
    public function getLockFilePath(): string;

    /**
     * Get the ERE regex pattern to match the process name
     *
     * This is used to double check for running retention processes if the lock
     * file appears to be stale (old). The regex pattern should match all
     * possible invokation combinations of the symfony command.
     *
     * @return string
     */
    public function getProcessNamePattern(): string;

    public function getAsset(): Asset;

    /**
     * Get the description text about retention instance.
     *
     * Mostly used in log messages to denote retention type.
     *
     * @return string in lower case
     */
    public function getDescription(): string;

    public function isSupported(): bool;

    /**
     * Check if the strategy supports removing recovery points for archived assets.
     *
     * @return bool
     */
    public function isArchiveRemovalSupported(): bool;

    public function isDisabledByAdmin(): bool;

    public function isPaused(): bool;

    public function getSettings(): Retention;

    /**
     * Get the list of recovery points for given retention type.
     *
     * Filters out points that are critical or are used in active restore.
     *
     * @return int[] snapshot timestamps.
     */
    public function getRecoveryPoints(): array;

    /**
     * Deletes the recovery points from the given list.
     *
     * Will respect retetion limits if given retention type has any.
     *
     * @param array $pointsToDelete
     * @param bool $isNightly
     */
    public function deleteRecoveryPoints(
        array $pointsToDelete,
        bool $isNightly
    );
}
