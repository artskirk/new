<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\LinkBackup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Displays the status of network changes on the device.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class ChangesStatusCommand extends Command
{
    protected static $defaultName = 'network:changes';

    private LinkBackup $linkBackup;

    public function __construct(LinkBackup $linkBackup)
    {
        parent::__construct();
        $this->linkBackup = $linkBackup;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Gets the status ("none", "pending", or "reverted") of any Networking changes.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->linkBackup->getState());
        return 0;
    }
}
