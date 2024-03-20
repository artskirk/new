<?php

namespace Datto\App\Console\Command\Network;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that retrieves information about the device networking status,
 * without any parameter it retrieves everything.
 * You can specify whether you want interfaces, hostname or dns settings passing the appropriate flags.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class NetworkStatusCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:status';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription("Get status information about device's networking")
            ->addOption(
                "links",
                "l",
                InputOption::VALUE_NONE,
                "Show Network Link Status"
            )
            ->addOption(
                "hostname",
                "H",
                InputOption::VALUE_NONE,
                "Show devices's hostname configuration"
            )
            ->addOption(
                "dns",
                "D",
                InputOption::VALUE_NONE,
                "Show device's dns configuration"
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $showLinks = $input->getOption('links');
        $showHostname = $input->getOption('hostname');
        $showDns = $input->getOption('dns');

        $showAll = false;
        if (!$showLinks && !$showHostname && !$showDns) {
            $showAll = true;
        }

        $outputArray = [];

        if ($showLinks || $showAll) {
            $outputArray['links'] = $this->linkService->getLinks();
        }

        if ($showDns || $showAll) {
            $outputArray['dns'] = $this->networkService->getGlobalDns();
        }

        if ($showHostname || $showAll) {
            $outputArray['hostname'] = $this->networkService->getHostname();
        }

        $output->writeln(json_encode($outputArray, JSON_PRETTY_PRINT));
        return 0;
    }
}
