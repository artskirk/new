<?php

namespace Datto\App\Console\Command\Network;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to configure the hostname of the device.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ConfigureHostnameCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:configure:hostname';

    /**
     * {@inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription("Set device's hostname")
            ->addArgument(
                "hostname",
                InputArgument::REQUIRED,
                "New device's hostname"
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostname = $input->getArgument("hostname");
        $this->networkService->setHostname($hostname);
        return 0;
    }
}
