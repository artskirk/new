<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\System\AbstractSystemCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RebootCancelCommand
 *
 * This class implements a snapctl command that cancels a scheduled reboot.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RebootCancelCommand extends AbstractSystemCommand
{
    protected static $defaultName = 'system:reboot:cancel';

    protected function configure()
    {
        $this
            ->setDescription('Cancel scheduled reboot for the device.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->powerManager->cancel();
        return 0;
    }
}
