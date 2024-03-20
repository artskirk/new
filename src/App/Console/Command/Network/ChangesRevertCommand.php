<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\LinkBackup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to do an early revert of pending network changes, reverting them before an automatic rollback occurs.
 * Can also be used to acknowledge that an automatic revert/rollback occurred before changes could be committed.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class ChangesRevertCommand extends Command
{
    protected static $defaultName = 'network:changes:revert';

    private LinkBackup $linkBackup;

    public function __construct(LinkBackup $linkBackup)
    {
        parent::__construct();
        $this->linkBackup = $linkBackup;
    }

    protected function configure()
    {
        $this
            ->setDescription("Explicitly reverts pending network changes, before the timer-based automatic rollback occurs")
            ->addOption('ack', 'a', InputOption::VALUE_NONE, 'Just acknowledge a prior automatic rollback, dismissing the banner displayed on the UI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ack = $input->getOption('ack');

        if ($ack) {
            $this->linkBackup->acknowledgeRevert();
        } else {
            $this->linkBackup->revert();
        }
        return 0;
    }
}
