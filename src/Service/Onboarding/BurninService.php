<?php

namespace Datto\Service\Onboarding;

use Datto\Asset\AssetService;
use Datto\Common\Resource\Filesystem;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\DeviceState;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\System\MountManager;
use Datto\Utility\File\LockFactory;
use Datto\ZFS\ZfsDatasetService;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service class to encapsulate running burnin on new devices.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class BurninService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const RUNNER_LOCK_FILE = '/dev/shm/burnin.lock';

    const ROOT_BURNIN_LINK = '/root/burnin';
    const ROOT_CREATE_LINK = '/root/create-rand-file';

    const BURNIN_STATUS_KEY = 'burnin_status';
    const BURNIN_TIMEOUT_SECONDS = DateTimeService::SECONDS_PER_DAY * 7;

    const RAMFS_MOUNTPOINT = '/mnt/burnin-test';

    const BURNIN_DATASET_NAME = 'homePool/burn1';
    const BURNIN_DATASET_MOUNTPOINT = '/homePool/burn1';

    /** @var string */
    private $burninPyBinary;

    /** @var string */
    private $burninBinary;

    /** @var string */
    private $createRandFileBinary;

    /** @var DeviceState */
    private $deviceState;

    /** @var AssetService */
    private $assetService;

    /** @var LockFactory */
    private $lockFactory;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var MountManager */
    private $mountManager;

    /** @var ZfsDatasetService */
    private $zfsDatasetService;

    /** @var BurninResult */
    private $burninResult;

    /** @var Sleep */
    private $sleep;

    public function __construct(
        string $burninPyBinary,
        string $burninBinary,
        string $createRandFileBinary,
        DeviceState $deviceState,
        AssetService $assetService,
        LockFactory $lockFactory,
        ProcessFactory $processFactory,
        Filesystem $filesystem,
        PosixHelper $posixHelper,
        DateTimeService $dateTimeService,
        MountManager $mountManager,
        ZfsDatasetService $zfsDatasetService,
        BurninResult $burninResult,
        Sleep $sleep
    ) {
        $this->burninPyBinary = $burninPyBinary;
        $this->burninBinary = $burninBinary;
        $this->createRandFileBinary = $createRandFileBinary;
        $this->deviceState = $deviceState;
        $this->assetService = $assetService;
        $this->lockFactory = $lockFactory;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
        $this->posixHelper = $posixHelper;
        $this->dateTimeService = $dateTimeService;
        $this->mountManager = $mountManager;
        $this->zfsDatasetService = $zfsDatasetService;
        $this->burninResult = $burninResult;
        $this->sleep = $sleep;
    }

    /**
     * Do preflight checks to see if burnin can run.
     */
    public function preflight()
    {
        $assetCount = count($this->assetService->getAllKeyNames());
        if ($assetCount > 0) {
            $message = 'Burnin cannot start if the system has assets';
            $this->logger->warning('BRN0001 ' . $message);
            throw new Exception($message);
        }

        $status = $this->getStatus();

        if (in_array($status->getState(), [BurninStatus::STATE_ERROR, BurninStatus::STATE_FINISHED])) {
            $message = 'Burnin cannot start if it has already been run. ' .
                'Please reset the system and use "snapctl burnin:status:reset".';
            $this->logger->warning('BRN0002 ' . $message);
            throw new Exception($message);
        }
    }

    /**
     * Start running burnin if it can be run. If it has already been run, then it will fail. Please check the status
     * before calling start.
     *
     * @return bool True if it was started and False if it was already started.
     */
    public function start(): bool
    {
        $this->logger->info('BRN0003 Burnin start request received');

        $this->reconcile();
        $this->preflight();

        try {
            $this->lock();
            $status = $this->readStatus();
            if ($status->getState() === BurninStatus::STATE_RUNNING) {
                return false;
            } else {
                $this->setStatus(BurninStatus::pending());
            }
        } finally {
            $this->unlock();
        }

        try {
            $this->startBackgroundRunner();

            return true;
        } catch (Throwable $e) {
            $startedAt = $finishedAt = $this->dateTimeService->getTime();

            try {
                $this->lock();
                $this->setStatus(BurninStatus::error(
                    $startedAt,
                    $finishedAt,
                    $e->getMessage()
                ));
            } finally {
                $this->unlock();
            }

            $this->logger->error('BRN0004 Could not start burnin worker in background', ['exception' => $e]);
            throw new Exception('Could not start burnin worker in background', 0, $e);
        }
    }

    /**
     * Internal run command that is executed by a worker process inside of a screen. Not intended to be called
     * by client code.
     */
    public function run()
    {
        $this->logger->debug('BRN0005 Burnin runner is attempting to start');

        $startedAt = $this->dateTimeService->getTime();
        $pid = $this->posixHelper->getCurrentProcessId();

        try {
            $this->lock();
            $status = $this->getStatus();

            $this->preflight();

            if ($status->getState() !== BurninStatus::STATE_PENDING) {
                $message = 'Burnin runner expected status to be pending';
                $this->logger->error('BRN0006 ' . $message, [
                    'state' => $status->getState()
                ]);
                throw new Exception($message);
            }

            $this->setStatus(BurninStatus::running($pid, $startedAt));
        } finally {
            $this->unlock();
        }

        try {
            $this->logger->info('BRN0007 Burnin started');

            try {
                $this->cleanup();
                $this->prepare();
                $this->burnin();
            } finally {
                $this->cleanup();
            }

            $this->setStatus(BurninStatus::finished(
                $startedAt,
                $this->dateTimeService->getTime()
            ));

            $this->logger->info('BRN0008 Burnin finished');
        } catch (Throwable $e) {
            $this->logger->error('BRN0009 Burnin failed', [
                'exceptionMessage' => $e->getMessage(),
                'exceptionTrace' => $e->getTraceAsString()
            ]);

            $this->setStatus(BurninStatus::error($startedAt, $this->dateTimeService->getTime(), $e->getMessage()));

            throw $e;
        }
    }

    /**
     * Get the finished result of burnin. This is a collection of system stats can that be used to check if the
     * system is in a healthy state.
     *
     * @return array
     */
    public function getFinishedResult(): array
    {
        $status = $this->getStatus();

        if ($status->getState() !== BurninStatus::STATE_FINISHED) {
            $message = 'Cannot get finished result of unfinished burnin, current state is ' . $status->getState();
            $this->logger->debug('BRN0010 ' . $message, [
                'state' => $status->getState()
            ]);
            throw new Exception($message);
        }

        return $this->burninResult->get();
    }

    /**
     * Get the status of burnin.
     *
     * @return BurninStatus
     */
    public function getStatus(): BurninStatus
    {
        $this->reconcile();

        return $this->readStatus();
    }

    /**
     * Reset the status of burnin. This is primarily used for testing and, in the case where burnin fails in
     * production and needs to be re-run after the system is manually cleaned up.
     */
    public function resetStatus()
    {
        $this->logger->info('BRN0011 Resetting burnin status to "never_run"');

        $this->deviceState->clear(self::BURNIN_STATUS_KEY);
    }

    /**
     * Helper method to return the status without triggering reconcile.
     *
     * @return BurninStatus
     */
    private function readStatus()
    {
        $statusContent = $this->deviceState->get(self::BURNIN_STATUS_KEY);
        $statusArray = json_decode($statusContent, true);

        return BurninStatus::fromArray($statusArray);
    }

    private function setStatus(BurninStatus $status)
    {
        $this->deviceState->set(self::BURNIN_STATUS_KEY, json_encode($status->toArray()));
    }

    /**
     * Check the status and the state of the system to make sure they match. If they don't match, transition into
     * an error state or fix if possible.
     */
    private function reconcile()
    {
        try {
            $this->lock();

            $status = $this->readStatus();

            if ($status->getState() === BurninStatus::STATE_RUNNING) {
                if (!$this->posixHelper->isProcessRunning($status->getPid())) {
                    $this->setStatus(BurninStatus::error(
                        $status->getStartedAt(),
                        $this->dateTimeService->getTime(),
                        'Burnin process was supposed to be running but it wasn\'t found (pid: '
                        . $status->getPid() . ')'
                    ));
                }
            }
        } finally {
            $this->unlock();
        }
    }

    private function startBackgroundRunner()
    {
        $this->logger->debug('BRN0012 Starting burnin worker screen');

        $this->processFactory->get([
                'snapctl',
                'internal:burnin:run',
                '--background'
            ])
            ->mustRun();

        $tries = 15;
        while ($tries-- > 0) {
            if ($this->getStatus()->getState() !== BurninStatus::STATE_PENDING) {
                $this->logger->debug('BRN0013 Burnin worker started');

                return;
            } else {
                $this->sleep->sleep(1);
            }
        }

        throw new Exception('Tried to start burnin worker but it never started');
    }

    private function lock()
    {
        $lock = $this->lockFactory->getProcessScopedLock(self::RUNNER_LOCK_FILE);

        if (!$lock->exclusiveAllowWait(30)) {
            $message = 'Could not obtain burnin status lock';
            $this->logger->debug('BRN0014 ' . $message);
            throw new Exception($message);
        }
    }

    private function unlock()
    {
        $lock = $this->lockFactory->getProcessScopedLock(self::RUNNER_LOCK_FILE);
        $lock->unlock();
    }

    private function prepare()
    {
        $this->logger->debug('BRN0015 Preparing burnin');

        $this->filesystem->symlink($this->burninBinary, self::ROOT_BURNIN_LINK);
        $this->filesystem->symlink($this->createRandFileBinary, self::ROOT_CREATE_LINK);
    }

    private function burnin()
    {
        $this->logger->debug('BRN0016 Running burnin');

        $process = $this->processFactory->get([
                'python3',
                $this->burninPyBinary,
                '--burn',
                '-z',
                dirname(self::BURNIN_DATASET_NAME),
                '--dataset',
                basename(self::BURNIN_DATASET_NAME),
                '--fs-path',
                self::BURNIN_DATASET_MOUNTPOINT,
                '--ramfs',
                self::RAMFS_MOUNTPOINT
            ])
            ->setTimeout(self::BURNIN_TIMEOUT_SECONDS);
        $process->mustRun();
    }

    private function cleanup()
    {
        $this->logger->debug('BRN0017 Cleaning up burnin');

        $this->filesystem->unlinkIfExists(self::ROOT_BURNIN_LINK);
        $this->filesystem->unlinkIfExists(self::ROOT_CREATE_LINK);

        if ($this->mountManager->isMounted(self::RAMFS_MOUNTPOINT)) {
            $this->logger->debug('BRN0018 Burnin ramfs mountpoint was leftover, unmounting it');
            $this->mountManager->unmount(self::RAMFS_MOUNTPOINT);
        }

        $dataset = $this->zfsDatasetService->findDataset(self::BURNIN_DATASET_NAME);
        if ($dataset) {
            $this->logger->debug('BRN0019 Burnin dataset was leftover, destroying it');
            $this->zfsDatasetService->destroyDataset($dataset);
        }
    }
}
