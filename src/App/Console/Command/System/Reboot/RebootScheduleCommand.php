<?php

namespace Datto\App\Console\Command\System\Reboot;

use Datto\App\Console\Command\System\AbstractSystemCommand;
use Datto\System\RebootException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class RebootScheduleCommand
 *
 * This class implements a snapctl command that schedules a reboot.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RebootScheduleCommand extends AbstractSystemCommand
{
    protected static $defaultName = 'system:reboot:schedule';

    protected function configure()
    {
        $this
            ->setDescription('Manage reboot scheduling for the device.')
            ->addArgument('time', InputArgument::REQUIRED, 'Time you wish to set the reboot (epoch)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $time = $input->getArgument('time');

        try {
            $this->powerManager->setRebootDateTime($time);
            return 0;
        } catch (RebootException $e) {
            $output->write($e->getMessage());
            return 1;
        }
    }

    protected function validateArgs(InputInterface $input): void
    {
        $time = $input->getArgument('time');
        $this->validator->validateValue(
            $time,
            new Assert\GreaterThan(time())
        );
    }
}
