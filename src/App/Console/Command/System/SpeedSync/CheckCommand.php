<?php

namespace Datto\App\Console\Command\System\SpeedSync;

use Datto\Cloud\SpeedSyncMaintenanceService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check if SpeedSync is paused and clean up pause and reconcile state with device-web.
 *
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class CheckCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'system:speedsync:check';

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
            ->setDescription('Check the SpeedSync pause state and clean up stale pause files.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->speedSyncMaintenanceService->check();

        if ($this->speedSyncMaintenanceService->isDevicePaused()) {
            $dateText = $this->speedSyncMaintenanceService->isDelayIndefinite() ?
                'indefinitely' :
                sprintf('until %s', date("g:ia M jS Y", $this->speedSyncMaintenanceService->getResumeTime()));
            $this->logger->debug(sprintf('SMC0001 SpeedSync is paused device-wide %s.', $dateText));
        } else {
            $this->logger->debug('SMC0002 Device-wide SpeedSync pause is not in place.');
        }

        $this->speedSyncMaintenanceService->checkAssets();
        $pausedAssetNames = $this->speedSyncMaintenanceService->getPausedAssetNames();
        if ($pausedAssetNames) {
            $this->logger->debug(
                sprintf(
                    'SMC0003 SpeedSync is currently paused for the following asset(s): %s',
                    implode(', ', $this->speedSyncMaintenanceService->getPausedAssetNames())
                )
            );
        }
        return 0;
    }
}
