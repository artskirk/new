<?php

namespace Datto\App\Console\Command\System;

use Datto\Service\Storage\DriveHealthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display the health information for the drives on the system.
 */
class DriveHealthCommand extends Command
{
    protected static $defaultName = 'system:drive:health';

    private DriveHealthService $driveHealthService;

    public function __construct(
        DriveHealthService $driveHealthService
    ) {
        parent::__construct();
        $this->driveHealthService = $driveHealthService;
    }

    protected function configure(): void
    {
        $this->setDescription('Get health information for the drives on the system.')
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Query the drives for updated information');
            //->addOption('json', 'j', InputOption::VALUE_NONE, 'Display drive health in JSON format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('update')) {
            $this->driveHealthService->updateDriveHealth();
        }
        $drives = $this->driveHealthService->getDriveHealth();
        $missing = $this->driveHealthService->getMissing();

        // TODO: If we add pretty-printing, we should leave this, but wrap in a `--json` option
        $out = ['drives' => $drives];
        if ($missing) {
            $out['missing'] = $missing;
        }
        $output->writeln(json_encode($out));

        return 0;
    }
}
