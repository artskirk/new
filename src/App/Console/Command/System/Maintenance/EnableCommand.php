<?php

namespace Datto\App\Console\Command\System\Maintenance;

use Datto\App\Console\Command\SessionUserHelper;
use Datto\System\MaintenanceModeService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Enable maintenance mode (inhibitAllCron) for a configurable
 * amount of time. See MaintenanceModeService for details.
 *
 * @author Philipp Heckel <ph@datto.com>
 * @codeCoverageIgnore
 */
class EnableCommand extends Command
{
    protected static $defaultName = 'system:maintenance:enable';

    /** @var MaintenanceModeService */
    private $maintenanceModeService;

    /** @var SessionUserHelper */
    private $sessionUserHelper;

    public function __construct(
        MaintenanceModeService $maintenanceModeService,
        SessionUserHelper $sessionUserHelper
    ) {
        parent::__construct();

        $this->maintenanceModeService = $maintenanceModeService;
        $this->sessionUserHelper = $sessionUserHelper;
    }

    protected function configure()
    {
        $this
            ->setDescription('Enable maintenance mode (inhibitAllCron) for a few hours (default is 6 hours)')
            ->addOption('hours', 'H', InputOption::VALUE_REQUIRED, 'Number of hours to enable the flag for (1-48, default is 6).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = $input->getOption('hours');

        if (!$hours) {
            $hours = MaintenanceModeService::DEFAULT_ENABLE_TIME_IN_HOURS;
        } else {
            if (!is_numeric($hours)) {
                throw new Exception('Invalid argument for --hours. Must be an integer between 1-48.');
            }

            $hours = intval($hours);

            if ($hours < 1 || $hours > 48) {
                throw new Exception('Invalid argument for --hours. Must be an integer between 1-48.');
            }
        }

        $user = $this->sessionUserHelper->getRlyUser() ?: $this->sessionUserHelper->getSystemUser();
        $this->maintenanceModeService->enable($hours, $user);

        $formattedDate = date('g:ia M jS Y', $this->maintenanceModeService->getEndTime());
        $output->writeln('Maintenance mode enabled until ' . $formattedDate);
        return 0;
    }
}
