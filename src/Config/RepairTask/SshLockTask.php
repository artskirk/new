<?php

namespace Datto\Config\RepairTask;

use Datto\Core\Configuration\ConfigRepairTaskInterface;
use Datto\Common\Utility\Filesystem;
use Datto\System\Ssh\SshLockService;

/**
 * Handle updating device state to new SSH locking scheme.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SshLockTask implements ConfigRepairTaskInterface
{
    /** @var SshLockService */
    private $sshLockService;

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param SshLockService $sshLockService
     * @param Filesystem $filesystem
     */
    public function __construct(
        SshLockService $sshLockService,
        Filesystem $filesystem
    ) {
        $this->sshLockService = $sshLockService;
        $this->filesystem = $filesystem;
    }

    /**
     * @inheritdoc
     */
    public function run(): bool
    {
        if ($this->sshLockService->lockStatus() !== SshLockService::LOCK_STATUS_UNKNOWN) {
            return false;
        }

        if ($this->filesystem->exists(SshLockService::SSHD_NO_RUN_FILE)) {
            $this->sshLockService->lock();
        } else {
            $this->sshLockService->unlock();
        }
        return true;
    }
}
