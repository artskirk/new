<?php

namespace Datto\App\Console\Command\System\SpeedSync;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resume SpeedSync after pause.
 *
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class ResumeCommand extends Command
{
    protected static $defaultName = 'system:speedsync:resume';

    /** @var SpeedSyncMaintenanceService */
    private $speedSyncMaintenanceService;

    public function __construct(
        SpeedSyncMaintenanceService $speedSyncMaintenanceService
    ) {
        parent::__construct();

        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Resume SpeedSync operations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->speedSyncMaintenanceService->isDevicePaused()) {
            $this->speedSyncMaintenanceService->resume();
        }

        $output->writeln('SpeedSync is running.');
        return 0;
    }
}
