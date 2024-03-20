<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\LinkBackup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to commit pending networking changes on the device.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class ChangesCommitCommand extends Command
{
    protected static $defaultName = 'network:changes:commit';

    private LinkBackup $linkBackup;

    public function __construct(LinkBackup $linkBackup)
    {
        parent::__construct();
        $this->linkBackup = $linkBackup;
    }

    protected function configure()
    {
        $this->setDescription("Commits pending networking changes, stopping them from being automatically reverted when the timer expires");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->linkBackup->commit();
        return 0;
    }
}
