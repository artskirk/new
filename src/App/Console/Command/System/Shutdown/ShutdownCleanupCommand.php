<?php

namespace Datto\App\Console\Command\System\Shutdown;

use Datto\System\RebootReportHelper;
use Datto\System\ShutdownService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command class for cleaning up when the device is shutting down
 * This command is intended to be run by systemd datto-device-shutdown.service at shutdown
 *
 * @author Shawn Carpenter <scarpenter@datto.com>
 */
class ShutdownCleanupCommand extends Command
{
    protected static $defaultName = 'system:shutdown:cleanup';

    /** @var RebootReportHelper */
    private $rebootReportHelper;

    /** @var ShutdownService */
    private $shutdownService;

    public function __construct(
        RebootReportHelper $rebootReportHelper,
        ShutdownService $shutdownService
    ) {
        parent::__construct();

        $this->rebootReportHelper = $rebootReportHelper;
        $this->shutdownService = $shutdownService;
    }

    protected function configure()
    {
        $this->setDescription('Clean up the device before it is shut down');
        $this->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Cleaning up before shutdown...');
        $this->rebootReportHelper->markAsClean();
        $this->shutdownService->shutdownCleanup();
        $output->writeln('Cleanup before shutdown complete');
        return 0;
    }
}
