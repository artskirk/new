<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\System\AbstractSystemCommand;
use Datto\System\RebootException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class RebootAttemptCommand
 *
 * This class implements logic for a snapctl command that attempts to
 * reboot the device if a reboot is scheduled.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RebootAttemptCommand extends AbstractSystemCommand
{
    protected static $defaultName = 'system:reboot:attempt';

    protected function configure()
    {
        $this
            ->setDescription('Attempt to perform a scheduled reboot for the device.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->powerManager->attemptRebootIfScheduled();
            return 0;
        } catch (RebootException $e) {
            $output->writeln($e->getMessage());
            return 1;
        }
    }
}
