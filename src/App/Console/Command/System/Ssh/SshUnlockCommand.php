<?php

namespace Datto\App\Console\Command\System\Ssh;

use Datto\System\Ssh\SshLockService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Unlock SSH logins
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SshUnlockCommand extends Command
{
    protected static $defaultName = 'system:ssh:unlock';

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
        $this->setDescription('Allow user login via SSH');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sshLockService->unlock();
        return 0;
    }
}
