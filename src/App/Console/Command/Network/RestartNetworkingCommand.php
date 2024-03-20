<?php

namespace Datto\App\Console\Command\Network;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that restarts networking services on the device.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class RestartNetworkingCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:restart';

    protected function configure()
    {
        $this
            ->setDescription("Restart device's networking services");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->linkService->restartNetworking();
        return 0;
    }
}
