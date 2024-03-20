<?php

namespace Datto\Asset\RecoveryPoint;

/**
 * Manages list of recovery points (snapshots) for Agents and Shares
 *
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class RecoveryPoints
{
    /**
     * List of recovery point defined by it's epoch time (key)
     *
     * @var RecoveryPoint[]
     */
    private $points = array();

    /**
     * Add new recovery point.
     *
     * @param RecoveryPoint $recoveryPoint
     */
    public function add(RecoveryPoint $recoveryPoint): void
    {
        if ($this->exists($recoveryPoint->getEpoch())) {
            throw new \Exception('That recovery point already exists.');
        }
        // we want to use hash table here for performance reason (see in_array vs array_key_exists)
        $this->points[$recoveryPoint->getEpoch()] = $recoveryPoint;
    }

    /**
     * Edit an existing recovery point or adds a new one if it doesn't exist. Maintains order of keys
     * in internal array, avoiding the need for a ksort.
     *
     * @param RecoveryPoint $recoveryPoint
     */
    public function set(RecoveryPoint $recoveryPoint): void
    {
        $this->points[$recoveryPoint->getEpoch()] = $recoveryPoint;
    }

    /**
     * @param string $epoch
     * @return bool true if such recovery point exists
     */
    public function exists($epoch)
    {
        return array_key_exists($epoch, $this->points);
    }

    /**
     * @param $epoch
     * @return RecoveryPoint|null The RecoveryPoint object for the given snapshot epoch time
     */
    public function get($epoch)
    {
        return ($this->exists($epoch)) ? $this->points[$epoch] : null;
    }

    /**
     * @return RecoveryPoint[] array of all recovery points, indexed by epoch time
     */
    public function getAll()
    {
        return array_filter($this->points, function ($point) {
            return !$point->isDeleted();
        });
    }

    /**
     * @return RecoveryPoint[] array of all deleted recovery points, indexed by epoch time
     */
    public function getDeleted()
    {
        return array_filter($this->points, function ($point) {
            return $point->isDeleted();
        });
    }

    /**
     * @return RecoveryPoint[] array of all recovery points, living and dead, indexed by epoch time
     */
    public function getBoth()
    {
        return $this->points;
    }

    /**
     * @return int[] array of all recovery point epoch times
     */
    public function getAllRecoveryPointTimes()
    {
        $epochs = array_keys($this->getAll());
        sort($epochs);

        return $epochs;
    }

    /**
     * @return RecoveryPoint|null
     */
    public function getLast()
    {
        $snapshots = array(0 => null);
        if ($this->size() > 0) {
            $snapshots = array_keys($this->points);
            rsort($snapshots);
        }
        return ($snapshots[0] === null) ? null : $this->points[$snapshots[0]];
    }

    /**
     * Get the next recovery point that comes after the specified epoch.
     *
     * @param int $epoch Get the next (newer) recovery point after this epoch.
     * @return RecoveryPoint|null
     */
    public function getNextNewer(int $epoch)
    {
        foreach ($this->getAll() as $recoveryPoint) {
            if ($recoveryPoint->getEpoch() > $epoch) {
                return $recoveryPoint;
            }
        }

        return null;
    }

    /**
     * @param int $epoch
     * @return RecoveryPoint[]
     */
    public function getNewerThan(int $epoch): array
    {
        $filtered = [];

        foreach ($this->getAll() as $recoveryPoint) {
            if ($recoveryPoint->getEpoch() > $epoch) {
                $filtered[] = $recoveryPoint;
            }
        }

        return $filtered;
    }

    /**
     * Removes the recovery point at the given epoch time from the list of points
     *
     * @param string $epoch Unix timestamp of the recovery point
     */
    public function remove($epoch): void
    {
        if (isset($this->points[$epoch])) {
            unset($this->points[$epoch]);
        }
    }

    /**
     * Remove all recovery points.
     */
    public function removeAll(): void
    {
        $this->points = [];
    }

    /**
     * @FIXME This function returns the count of existing _and_ deleted points, and some callers expect it to return
     * only existing points
     *
     * @return int
     */
    public function size()
    {
        return count($this->points);
    }

    /**
     * Return the most recent RecoveryPoint that has been flagged for ransomware.
     *
     * @return RecoveryPoint|null The most recent RecoveryPoint that has been flagged for ransomware, or null if
     * no points have been flagged.
     */
    public function getMostRecentPointWithRansomware()
    {
        $pointsWithRansomware = array_filter($this->getBoth(), function (RecoveryPoint $point) {
            $results = $point->getRansomwareResults();
            return $results && $results->hasRansomware();
        });
        return count($pointsWithRansomware) > 0 ? $this->get(max(array_keys($pointsWithRansomware))) : null;
    }

    /**
     * Return the most recent RecoveryPoint that ran screenshot verification.
     *
     * @return RecoveryPoint|null The most recent RecoveryPoint that has run screenshot verification, or null if
     * no points have run screenshot verification.
     */
    public function getMostRecentPointWithScreenshot()
    {
        $pointsWithScreenshot = array_filter($this->getAll(), function (RecoveryPoint $point) {
            return $point->getVerificationScreenshotResult() !== null;
        });
        return count($pointsWithScreenshot) > 0 ? $this->get(max(array_keys($pointsWithScreenshot))) : null;
    }

    /**
     * Return the most recent RecoveryPoint that ran screenshot successful verification or null if none exists.
     *
     * @return RecoveryPoint|null The most recent RecoveryPoint that has run screenshot verification, or null if
     * no points have run screenshot verification.
     */
    public function getMostRecentGoodScreenshot()
    {
        $goodScreenshotPoints = array_filter($this->getBoth(), function (RecoveryPoint $point) {
            $exists = $point->getVerificationScreenshotResult() !== null;
            return $exists && $point->getVerificationScreenshotResult()->isSuccess();
        });
        return count($goodScreenshotPoints) > 0 ? $this->get(max(array_keys($goodScreenshotPoints))) : null;
    }

    /**
     * Return true if the last count screenshots were bad
     *
     * @param int $count
     *
     * @return bool True if the last $count screenshots were bad
     */
    public function isLastCountScreenshotsBad(int $count): bool
    {
        $screenshotPoints = array_filter($this->getBoth(), function (RecoveryPoint $point) {
            return $point->getVerificationScreenshotResult() !== null;
        });
        $badCount = 0;
        krsort($screenshotPoints);
        foreach ($screenshotPoints as $screenshotPoint) {
            $screenshotSuccess = $screenshotPoint->getVerificationScreenshotResult()->isSuccess();
            $badCount = $screenshotSuccess ? $badCount : $badCount + 1;
            if ($screenshotSuccess || $badCount >= $count) {
                break;
            }
        }
        return $badCount >=  $count;
    }

    /**
     * Return true if diffmerge since last good screenshot
     *
     * @param string $osVolumeGuid
     *
     * @return bool True there was a diffmerge since last good screenshot
     */
    public function hasDiffmergeSinceLastGoodScreenshot(string $osVolumeGuid): bool
    {
        $mostRecentGoodScreenshot = $this->getMostRecentGoodScreenshot();
        if ($mostRecentGoodScreenshot === null) {
            return false;
        }
        $lastGoodScreenshotEpoch = $mostRecentGoodScreenshot->getEpoch();
        $screenshotPoints = $this->getBoth();
        krsort($screenshotPoints);
        foreach ($screenshotPoints as $screenshotPoint) {
            if ($lastGoodScreenshotEpoch !== null && $screenshotPoint->getEpoch() ===  $lastGoodScreenshotEpoch) {
                break;
            }
            $backupTypes = $screenshotPoint->getVolumeBackupTypes();
            if (key_exists($osVolumeGuid, $backupTypes) &&
                isset($backupTypes[$osVolumeGuid]) &&
                $backupTypes[$osVolumeGuid] === RecoveryPoint::VOLUME_BACKUP_TYPE_DIFFERENTIAL
            ) {
                return true;
            }
        }
        return false;
    }
}
