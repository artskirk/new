<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Input\InputArgument;
use Datto\Utility\Network\IpAddress;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CheckIpCommand
 *
 * @author Mario Rial <mrial@datto.com>
 */
class CheckIpCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:checkip';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription("Check if the specified ip address is not used in the device's network")
            ->addArgument(
                'ip',
                InputArgument::REQUIRED,
                'The network interface to configure'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ipAddress = $input->getArgument('ip');

        if (!$this->networkService->isIpAvailable(IpAddress::fromAddr($ipAddress))) {
            throw new \Exception('The ip address is being used in the network and is not available for use.');
        }
        return 0;
    }
}
