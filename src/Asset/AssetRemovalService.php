<?php

namespace Datto\Asset;

use Datto\App\Console\SnapctlApplication;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\DestroyAgentService;
use Datto\Asset\Share\DestroyShareService;
use Datto\Asset\Share\Share;
use Datto\Common\Resource\ProcessFactory;
use Datto\Config\AgentConfig;
use Datto\Config\AgentConfigFactory;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\PosixHelper;
use Datto\Utility\File\Lock;
use Datto\Utility\File\LockFactory;
use Datto\Utility\Process\Ps;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class AssetRemovalService
{
    const REMOVING_KEY = 'removing';
    const REMOVING_KEY_FORMAT = AgentConfig::BASE_KEY_CONFIG_PATH . '/%s.' . self::REMOVING_KEY;

    const LOCK_WAIT_SECONDS = 15;
    const MAX_REMOVING_SECONDS = 3 * DateTimeService::SECONDS_PER_HOUR * DateTimeService::HOURS_PER_DAY;

    /** @var DeviceLoggerInterface */
    private $logger;

    /** @var AssetService */
    private $assetService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    /** @var LockFactory */
    private $lockFactory;

    /** @var PosixHelper */
    private $posixHelper;

    /** @var ProcessFactory */
    private $processFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Ps */
    private $ps;

    /** @var DestroyShareService */
    private $destroyShareService;

    /** @var DestroyAgentService */
    private $destroyAgentService;

    public function __construct(
        DeviceLoggerInterface $logger,
        AssetService $assetService,
        AgentConfigFactory $agentConfigFactory,
        LockFactory $lockFactory,
        PosixHelper $posixHelper,
        ProcessFactory $processFactory,
        DateTimeService $dateTimeService,
        Ps $ps,
        DestroyShareService $destroyShareService,
        DestroyAgentService $destroyAgentService
    ) {
        $this->logger = $logger;
        $this->assetService = $assetService;
        $this->agentConfigFactory = $agentConfigFactory;
        $this->lockFactory = $lockFactory;
        $this->posixHelper = $posixHelper;
        $this->processFactory = $processFactory;
        $this->dateTimeService = $dateTimeService;
        $this->ps = $ps;
        $this->destroyShareService = $destroyShareService;
        $this->destroyAgentService = $destroyAgentService;
    }

    /**
     * Queue up an asset removal to execute in the background
     *
     * @param string $assetKey
     * @param bool $force
     * @return bool True if newly queued, False if already queued.
     */
    public function enqueueAssetRemoval(string $assetKey, bool $force = false)
    {
        $asset = $this->assetService->get($assetKey);
        $this->assertCanDestroy($asset);

        try {
            $lock = $this->acquireLock($assetKey);

            $status = $this->getStatus($assetKey);
            $removing = $status->getState() === AssetRemovalStatus::STATE_PENDING
                || $status->getState() === AssetRemovalStatus::STATE_REMOVING;
            if ($removing) {
                return false;
            }

            $this->setStatus($assetKey, AssetRemovalStatus::pending($force));

            $this->logger->info('ARE0001 Asset has been marked for removal', ['assetKey' => $assetKey]);

            $this->startRemovalProcess($assetKey, $force);

            return true;
        } finally {
            if (isset($lock)) {
                $lock->unlock();
            }
        }
    }

    /**
     * Synchronously removes an asset.
     *
     * @param string $assetKey
     * @param bool $force If true, bypass some checks and try to remove
     */
    public function removeAsset(string $assetKey, bool $force = false): void
    {
        $this->executeRemoveAsset($assetKey, $force, $preserveDataset = false);
    }

    /**
     * Synchronously removes an asset, but preserve the zfs dataset
     *
     * @param string $assetKey
     * @param bool $force If true, bypass some checks and try to remove
     */
    public function removeAssetMetadata(string $assetKey, bool $force): void
    {
        $this->executeRemoveAsset($assetKey, $force, $preserveDataset = true);
    }

    /**
     * Synchronously removes an asset.
     *
     * @param string $assetKey
     * @param bool $force If true, bypass some checks and try to remove
     * @param bool $preserveDataset if true, do not delete the dataset
     */
    private function executeRemoveAsset(string $assetKey, bool $force = false, bool $preserveDataset = false): void
    {
        $asset = $this->assetService->get($assetKey);
        $this->assertCanDestroy($asset);

        try {
            $lock = $this->acquireLock($assetKey);
            $status = $this->getStatus($assetKey);

            if ($status->getState() === AssetRemovalStatus::STATE_REMOVING) {
                throw new Exception(sprintf(
                    'Another removal process may be running (pid: %d). Could not remove asset.',
                    $status->getPid()
                ));
            }

            $pid = $this->posixHelper->getCurrentProcessId();
            $this->setStatus($assetKey, AssetRemovalStatus::removing($pid));
        } finally {
            if (isset($lock)) {
                $lock->unlock();
            }
        }

        try {
            $this->destroy($asset, $force, $preserveDataset);

            $this->setStatusAtomic($assetKey, AssetRemovalStatus::removed($this->dateTimeService->getTime()));
        } catch (Throwable $e) {
            $this->setStatusAtomic($assetKey, AssetRemovalStatus::error(
                $e->getCode(),
                $e->getMessage()
            ));
            throw $e;
        }
    }

    /**
     * @param string $assetKey
     * @return AssetRemovalStatus
     */
    public function getAssetRemovalStatus(string $assetKey)
    {
        return $this->getStatus($assetKey);
    }

    /**
     * @return AssetRemovalStatus[]
     */
    public function getAssetRemovalStatuses()
    {
        $assetKeys = $this->agentConfigFactory->getAllKeyNamesWithKey(self::REMOVING_KEY);

        $statuses = [];
        foreach ($assetKeys as $assetKey) {
            $statuses[$assetKey] = $this->getStatus($assetKey);
        }

        return $statuses;
    }

    /**
     * Reconcile asset removals (check if processes are still running, if asset is enqueued, etc.)
     */
    public function reconcileAssetRemovals(): void
    {
        foreach ($this->agentConfigFactory->getAllKeyNamesWithKey(self::REMOVING_KEY) as $assetKey) {
            try {
                $lock = $this->acquireLock($assetKey);
                $status = $this->getStatus($assetKey);

                if ($status->getState() === AssetRemovalStatus::STATE_PENDING) {
                    $this->reconcilePending($assetKey, $status->isForce());
                } elseif ($status->getState() === AssetRemovalStatus::STATE_REMOVING) {
                    $pid = $status->getPid();

                    if ($this->posixHelper->isProcessRunning($pid)) {
                        $this->reconcileProcessRunning($assetKey, $pid);
                    } else {
                        $this->reconcileProcessDead($assetKey);
                    }
                } elseif ($status->getState() === AssetRemovalStatus::STATE_NONE) {
                    $this->reconcileEmptyStatus($assetKey);
                }
            } catch (Throwable $e) {
                $this->logger->warning('ARE0003 Could not check status of asset', ['assetKey' => $assetKey]);
            } finally {
                if (isset($lock)) {
                    $lock->unlock();
                }
            }
        }
    }

    /**
     * @param string $assetKey
     * @param bool $force
     */
    private function reconcilePending(string $assetKey, bool $force): void
    {
        $this->logger->debug('ARE0004 > Found in pending state, spawning removal process');
        $this->startRemovalProcess($assetKey, $force);
    }

    /**
     * @param string $assetKey
     * @param int $pid
     */
    private function reconcileProcessRunning(string $assetKey, int $pid): void
    {
        $this->logger->debug('ARE0005 > Found running pid, checking runtime');

        $entry = $this->ps->getFirstByPid($pid);
        $runtimeInSeconds = $entry->getRuntimeInSeconds();
        if ($runtimeInSeconds <= self::MAX_REMOVING_SECONDS) {
            $this->logger->debug(sprintf('ARE0006 > Process has been running for %d seconds', $runtimeInSeconds));
        } else {
            $this->posixHelper->kill($pid, PosixHelper::SIGNAL_KILL);

            $this->logger->debug(sprintf(
                'ARE0007 > Process has exceeded maximum runtime of %d and has been killed, updating status',
                self::MAX_REMOVING_SECONDS
            ));

            $status = AssetRemovalStatus::error(AssetRemovalStatus::ERROR_CODE_PROCESS_HUNG, sprintf(
                'Removal process exceeding maximum runtime of %d seconds and was killed',
                self::MAX_REMOVING_SECONDS
            ));
            $this->setStatus($assetKey, $status);
        }
    }

    /**
     * @param string $assetKey
     */
    private function reconcileProcessDead(string $assetKey): void
    {
        $this->logger->debug('ARE0008 > Found with dead pid, updating status');

        if ($this->assetService->exists($assetKey)) {
            $this->setStatus($assetKey, AssetRemovalStatus::error(
                AssetRemovalStatus::ERROR_CODE_PROCESS_DIED,
                'Removal process was not found and asset still exists'
            ));
        } else {
            $this->setStatus($assetKey, AssetRemovalStatus::removed($this->dateTimeService->getTime()));
        }
    }

    /**
     * @param string $assetKey
     */
    private function reconcileEmptyStatus(string $assetKey): void
    {
        $this->logger->debug('ARE0012 > Found empty removing key, removing status');

        $this->removeStatus($assetKey);
    }

    /**
     * @param string $assetKey
     * @param bool $force If true, bypass some checks and try to remove
     */
    private function startRemovalProcess(string $assetKey, bool $force): void
    {
        $this->logger->debug('ARE0009 Starting removal process for ' . $assetKey);

        $command = [
            SnapctlApplication::EXECUTABLE_NAME,
            'asset:remove',
            $assetKey,
            '--background'
        ];

        if ($force) {
            $command[] = '--force';
        }

        $this->processFactory->get($command)
            ->mustRun();
    }

    /**
     * Acquire a lock for the asset key's removal status file.
     *
     * @param string $assetKey
     * @return Lock A locked instance of Lock.
     */
    private function acquireLock(string $assetKey): Lock
    {
        $lock = $this->lockFactory->create($this->getRemovalStatusKeyFile($assetKey));

        if (!$lock->exclusiveAllowWait(self::LOCK_WAIT_SECONDS)) {
            $this->logger->debug('ARE0011 Could not obtain removal status lock');
            throw new Exception('Could not obtain removal status lock for ' . $assetKey);
        }

        return $lock;
    }

    /**
     * @param string $assetKey
     * @return AssetRemovalStatus
     */
    private function getStatus(string $assetKey)
    {
        $statusContent = $this->agentConfigFactory->create($assetKey)
            ->get(self::REMOVING_KEY);
        $status = json_decode($statusContent, true);
        return AssetRemovalStatus::fromArray($status);
    }

    /**
     * @param string $assetKey
     * @param AssetRemovalStatus $status
     */
    private function setStatus(string $assetKey, AssetRemovalStatus $status): void
    {
        $this->agentConfigFactory->create($assetKey)
            ->set(self::REMOVING_KEY, json_encode($status->toArray()));
    }

    /**
     * @param string $assetKey
     */
    private function removeStatus(string $assetKey): void
    {
        $this->agentConfigFactory->create($assetKey)
            ->clear(self::REMOVING_KEY);
    }

    /**
     * @param string $assetKey
     * @return string
     */
    private function getRemovalStatusKeyFile(string $assetKey)
    {
        return sprintf(self::REMOVING_KEY_FORMAT, $assetKey);
    }

    /**
     * Destroy an asset using the correct service.
     *
     * @param Asset $asset
     * @param bool $force If true, bypass some checks and try to remove
     * @param bool $preserveDataset
     */
    private function destroy(Asset $asset, bool $force, bool $preserveDataset): void
    {
        if ($asset->isType(AssetType::AGENT)) {
            /** @var Agent $asset */
            $this->destroyAgentService->destroy($asset, $force, $preserveDataset);
        } elseif ($asset->isType(AssetType::SHARE)) {
            /** @var Share $asset */
            $this->destroyShareService->destroy($asset, $force, $preserveDataset);
        } else {
            throw new AssetException('Unable to destroy asset. No associated service for this type of asset.');
        }
    }

    private function assertCanDestroy(Asset $asset): void
    {
        if ($asset->isType(AssetType::AGENT)) {
            /** @var Agent $asset */
            $this->destroyAgentService->assertCanDestroy($asset);
        } elseif ($asset->isType(AssetType::SHARE)) {
            /** @var Share $asset */
            $this->destroyShareService->assertCanDestroy($asset);
        } else {
            throw new AssetException('Unable to check if you can destroy asset. No associated service for this type of asset.');
        }
    }

    /**
     * Sets the status of the removal, acquiring and releasing the lock as necessary
     */
    private function setStatusAtomic(string $assetKey, AssetRemovalStatus $removalStatus): void
    {
        try {
            $lock = $this->acquireLock($assetKey);
            $this->setStatus($assetKey, $removalStatus);
        } finally {
            if (isset($lock)) {
                $lock->unlock();
            }
        }
    }
}
