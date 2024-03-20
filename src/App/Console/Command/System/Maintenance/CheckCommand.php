<?php

namespace Datto\App\Console\Command\System\Maintenance;

use Datto\System\MaintenanceModeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check if maintenance mode (inhibitAllCron) is enabled, and
 * disable it if the end time (as per the flag contents) has been reached.
 *
 * See MaintenanceModeService for details.
 *
 * @author Philipp Heckel <ph@datto.com>
 * @codeCoverageIgnore
 */
class CheckCommand extends Command
{
    protected static $defaultName = 'system:maintenance:check';

    /** @var MaintenanceModeService */
    private $maintenanceModeService;

    public function __construct(
        MaintenanceModeService $maintenanceModeService
    ) {
        parent::__construct();

        $this->maintenanceModeService = $maintenanceModeService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Check maintenance mode and disable if the end date is reached.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->maintenanceModeService->check();

        if ($this->maintenanceModeService->isEnabled()) {
            $output->writeln(sprintf('Maintenance mode enabled until %s', date("g:ia M jS Y", $this->maintenanceModeService->getEndTime())));
        } else {
            $output->writeln('Maintenance mode disabled.');
        }
        return 0;
    }
}
