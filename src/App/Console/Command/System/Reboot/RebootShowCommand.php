<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\System\AbstractSystemCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\System\RebootConfig;

/**
 * Class RebootShowCommand
 *
 * This class implements a snapctl command that shows the scheduled reboot
 * timestamp.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RebootShowCommand extends AbstractSystemCommand
{
    protected static $defaultName = 'system:reboot:show';

    protected function configure()
    {
        $this
            ->setDescription('Show the scheduled reboot for the device.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /* @var RebootConfig */
        $config = $this->powerManager->getRebootSchedule();
        if ($config) {
            $output->writeln($config->getRebootAt());
        } else {
            $output->writeln("No reboot scheduled");
        }
        return 0;
    }
}
