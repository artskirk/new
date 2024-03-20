<?php

namespace Datto\App\Console\Command\System;

use Datto\Service\Storage\DriveHealthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Displays any drives that the system believes are missing, and allows the user to remove a missing
 * drive from the list of tracked drives.
 */
class DriveResetMissingCommand extends Command
{
    protected static $defaultName = 'system:drive:resetMissing';

    private DriveHealthService $driveHealthService;

    public function __construct(
        DriveHealthService $driveHealthService
    ) {
        parent::__construct();
        $this->driveHealthService = $driveHealthService;
    }

    protected function configure(): void
    {
        $this->setDescription('Resets the list of missing drives on the system.')
            ->addOption(
                'serial',
                's',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Drive serial number(s) of individual drives to remove'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serials = $input->getOption('serial');
        if ($serials) {
            foreach ($serials as $serial) {
                $this->driveHealthService->acknowledgeMissing($serial);
            }
        } else {
            $this->driveHealthService->clearMissing();
        }

        $missing = $this->driveHealthService->getMissing();
        $output->writeln(json_encode($missing));
        return 0;
    }
}
