<?php

namespace Datto\App\Console\Command\Network;

use Datto\Service\Networking\ConnectivityService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks device connectivity with Datto servers.
 *
 * @author Andrew Mitchell <amitchell@datto.com>
 * @author Stephen Allan <sallan@datto.com>
 */
class ConnectivityTestCommand extends Command
{
    protected static $defaultName = 'network:connectivity:test';

    private ConnectivityService $connectivityService;

    public function __construct(
        ConnectivityService $connectivityService
    ) {
        parent::__construct();

        $this->connectivityService = $connectivityService;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Tests network connectivity with Datto servers.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Begin network connectivity check.');
        $isCron = $input->getOption('cron');

        $this->connectivityService->getConnectivityState(
            function (string $newStatus) use ($output) {
                $output->write($newStatus);
            },
            $isCron
        );
        $output->writeln('Network connectivity check complete.');
        return 0;
    }
}
