<?php

namespace Datto\App\Console\Command\System\SpeedSync;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Intended to be called from DeviceWeb during checkin, this command will resume off-site syncing of
 * the device in a manner separate from the local user resume. It replaces the legacy speedsync `rsyncSendDisabled`
 * flag for devices with SpeedSync 6.12+
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class CloudResumeCommand extends Command
{
    protected static $defaultName = 'system:speedsync:cloud:resume';

    private SpeedSyncMaintenanceService $speedSyncMaintenanceService;

    public function __construct(SpeedSyncMaintenanceService $speedSyncMaintenanceService)
    {
        parent::__construct();
        $this->speedSyncMaintenanceService = $speedSyncMaintenanceService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Remove any device-wide cloud pauses in effect')
            ->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->speedSyncMaintenanceService->cloudResume();
        return 0;
    }
}
