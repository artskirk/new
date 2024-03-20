<?php

namespace Datto\App\Console\Command\System\Maintenance;

use Datto\App\Console\Command\SessionUserHelper;
use Datto\System\MaintenanceModeService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Disable maintenance mode (inhibitAllCron) if it was enabled.
 * See MaintenanceModeService for details.
 *
 * @author Philipp Heckel <ph@datto.com>
 * @codeCoverageIgnore
 */
class DisableCommand extends Command
{
    protected static $defaultName = 'system:maintenance:disable';

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
            ->setDescription('Disable maintenance mode (inhibitAllCron)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->sessionUserHelper->getRlyUser() ?: $this->sessionUserHelper->getSystemUser();
        $this->maintenanceModeService->disable($user);

        if ($this->maintenanceModeService->isEnabled()) {
            throw new Exception('Failed to disable maintenance mode');
        }

        $output->writeln('Maintenance mode disabled.');
        return 0;
    }
}
