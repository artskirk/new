<?php

namespace Datto\App\Console\Command\System\Ssh;

use Datto\System\Ssh\SshLockService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Return whether or not SSH access is locked.
 *
 * Returns 0 on success, -1 if lock status is unknown.
 * Writes "locked" to standard out when SSH is locked.
 * Write "unlocked" to standard out when SSH is unlocked.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class SshLockStatusCommand extends Command
{
    protected static $defaultName = 'system:ssh:status';

    /** @var SshLockService */
    private $sshLockService;

    public function __construct(
        SshLockService $sshLockService
    ) {
        parent::__construct();

        $this->sshLockService = $sshLockService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setDescription(
            'Returns whether user login via SSH is locked (not allowed) or unlocked (allowed)'
        );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sshLockStatus = $this->sshLockService->lockStatus();
        $returnCode = 1;
        $lockStatusString = "unknown";

        if ($sshLockStatus === SshLockService::LOCK_STATUS_LOCKED) {
            $lockStatusString = "locked";
            $returnCode = 0;
        } elseif ($sshLockStatus === SshLockService::LOCK_STATUS_UNLOCKED) {
            $lockStatusString = "unlocked";
            $returnCode = 0;
        }

        $output->write($lockStatusString);
        return $returnCode;
    }
}
