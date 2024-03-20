<?php

namespace Datto\App\Console\Command\System\Ssh;

use Datto\System\Ssh\SshLockService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lock SSH logins
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class SshLockCommand extends Command
{
    protected static $defaultName = 'system:ssh:lock';

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
        $this->setDescription('Prevent user login via SSH');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->sshLockService->lock();
        return 0;
    }
}
