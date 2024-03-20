<?php

namespace Datto\BMR;

use Datto\App\Controller\Api\V1\Device\Asset\Agent\DatasetClone\Volume\Transfer;
use Datto\Asset\Agent\DatasetClone\MirrorService;
use Datto\Iscsi\Initiator;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\Differential\Rollback\DifferentialRollbackService;
use Datto\Restore\RestoreService;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Handles cleaning leftover BMR loops and ZFS clones
 * and iscsi entries!
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 * @author Justin Giacobbi <jgiacobbi@datto.com>
 */
class BmrCleaner implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const KEY_PATH_BASE = Transfer::KEY_PATH_BASE;
    const SUFFIX_PROGRESS = '.progress';
    const SUFFIX_CLEANED = '.cleaned';
    const OPTION_LOG_FILE = '-L';
    const TIMEOUT_FILE = 'bmrTimeout';

    /** @var AssetCloneManager */
    protected $cloneManager;

    /** @var Initiator */
    protected $initiator;

    /** @var MirrorService */
    protected $mirrorService;

    /** @var  DateTimeService */
    protected $dateTimeService;

    /** @var Filesystem */
    protected $fileSystem;

    /** @var RestoreService */
    private $restoreService;

    /** @var DifferentialRollbackService */
    private $differentialRollbackService;

    public function __construct(
        AssetCloneManager $cloneManger,
        Initiator $initiator,
        MirrorService $mirrorService,
        DateTimeService $dateTimeService,
        Filesystem $fileSystem,
        RestoreService $restoreService,
        DifferentialRollbackService $differentialRollbackService
    ) {
        $this->cloneManager = $cloneManger;
        $this->initiator = $initiator;
        $this->mirrorService = $mirrorService;
        $this->dateTimeService = $dateTimeService;
        $this->fileSystem = $fileSystem;
        $this->restoreService = $restoreService;
        $this->differentialRollbackService = $differentialRollbackService;
    }

    /**
     * Cleans up stale ZFS dataset clones
     * Stale dataset clones have been unused for one day
     *
     * @param bool $immediate Cleans up immediately (doesn't wait for one day)
     */
    public function cleanStaleBmrs(bool $immediate = false)
    {
        $zfsList = $this->cloneManager->getAllClones();

        foreach ($zfsList as $clone) {
            $agentKey = $clone->getAssetKey();
            $snap = $clone->getSnapshotName();
            $fullExtension = $clone->getSuffix();
            $shouldCheckClone =
                str_contains($fullExtension, 'bmr') ||
                str_contains($fullExtension, 'differential');

            if ($shouldCheckClone) {
                // Try checking last time the clone was used
                $lastActiveTime = $this->getLastProgressLogTimestamp($agentKey, $snap, $fullExtension);

                // post-6.0.0 datto-stick uses masclone to pull data not push it from the device
                $pullBmr = false;
                if ($lastActiveTime === null) {
                    // If push-masclone log is not present, or more than 1 day stale, this implies a pull BMR
                    $pullBmr = true;
                    $lastActiveTime = $this->getZfsCloneTimestamp($agentKey, $snap, $fullExtension);
                }

                $exceedsTimestamp = strtotime('+1 day', $lastActiveTime) <= $this->dateTimeService->getTime();

                if (($immediate || $exceedsTimestamp) && $pullBmr) {
                    try {
                        $this->differentialRollbackService->remove(
                            $agentKey,
                            $snap,
                            $fullExtension
                        );
                    } catch (Throwable $e) {
                        $this->logger->error(
                            "BMR0003 Error destroying dataset while cleaning BMR clones.",
                            ['exception' => $e, 'datasetName' => $clone->getTargetDatasetName()]
                        );
                    }
                }
            }
        }
    }

    /**
     * Cleans discoverydb
     */
    public function cleanDiscoveryDB()
    {
        $targets = $this->initiator->listRecords();

        foreach ($targets as $target) {
            $parts = explode(":", $target);
            $ip = $parts[0];

            if (!$this->mirrorService->running($ip, '')) {
                //stale targets
                $this->mirrorService->cleanup($ip);
            }
        }
    }

    /**
     * Grabs the most recent mtime of the progress files associated with the clone to approximate
     * when the clone was last used.
     *
     * @param $agent
     * @param $snap
     * @param $fullExtension
     *
     * @return int|null most recent timestamp if progress files exist, otherwise null
     */
    private function getLastProgressLogTimestamp($agent, $snap, $fullExtension)
    {
        /*
         * Example progress log file:
         *
         * /datto/config/keys/10.0.23.21-1500057141-bmr-00012e6e541a-dea60adc-0000-0000-0000-501f00000000-masclone.progress
         * <log base>         <agent>    <snap>     <fullExtension>  <volume guid>                        <suffix>
         *
         * Each volume has a log file and we can have multiple volumes per agent, so return the most recently
         * changed log file's mtime.
         */

        $logGlobString = sprintf(
            "%s%s-%s-%s-*-masclone%s",
            self::KEY_PATH_BASE,
            $agent,
            $snap,
            $fullExtension,
            self::SUFFIX_PROGRESS
        );
        $logs = $this->fileSystem->glob($logGlobString);

        $mostRecentTimestamp = 0;
        foreach ($logs as $log) {
            $mTime = $this->fileSystem->fileMTime($log);
            if (intval($mTime)) {
                $mostRecentTimestamp = max($mostRecentTimestamp, $mTime);
            }
        }

        return $mostRecentTimestamp !== 0 ? $mostRecentTimestamp : null;
    }

    /**
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @return string|null
     */
    private function getZfsCloneTimestamp($agent, $snapshot, $extension)
    {
        $timestampFile = $this->getCloneTimestampFile($agent, $snapshot, $extension);
        if ($this->fileSystem->exists($timestampFile)) {
            return $this->fileSystem->fileGetContents($timestampFile);
        }
        return null;
    }

    /**
     * @param string $agent
     * @param string $snapshot
     * @param string $extension
     * @return string
     */
    private function getCloneTimestampFile($agent, $snapshot, $extension)
    {
        return self::KEY_PATH_BASE . $agent . '-' . $snapshot . '-' . $extension . '.' . static::TIMEOUT_FILE;
    }
}
