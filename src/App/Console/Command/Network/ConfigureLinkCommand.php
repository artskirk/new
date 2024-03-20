<?php

namespace Datto\App\Console\Command\Network;

use Datto\App\Console\Input\InputArgument;
use Datto\Service\Networking\LinkBackup;
use Datto\Utility\Network\IpAddress;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureLinkCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:configure:link';

    protected function configure()
    {
        $this
            ->setDescription('Change your network settings.  ' . LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT)
            ->addArgument(
                "link",
                InputArgument::REQUIRED,
                "The UUID ID of the network link to configure"
            )
            ->addArgument(
                "mode",
                InputArgument::REQUIRED,
                "Set mode: dhcp|static|link-local|disabled"
            )
            ->addOption(
                "address",
                "A",
                InputOption::VALUE_REQUIRED,
                "IP address/mask (example: 192.168.1.1/24)"
            )
            ->addOption(
                "gateway",
                "G",
                InputOption::VALUE_REQUIRED
            )
            ->addOption(
                "jumbo",
                "j",
                InputOption::VALUE_REQUIRED,
                "Jumbo frames: enabled|disabled",
                false
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $linkId = $input->getArgument('link');
        $mode = $input->getArgument('mode');
        $addressRaw = $input->getOption('address');
        $gatewayRaw = $input->getOption('gateway');
        $jumboRaw = $input->getOption('jumbo');

        $jumbo = $jumboRaw === 'enabled';

        $deviceIpAddress = ($addressRaw) ? IpAddress::fromCidr($addressRaw) : null;
        $gatewayIpAddress = ($gatewayRaw) ? IpAddress::fromAddr($gatewayRaw) : null;

        if ($addressRaw && !$deviceIpAddress) {
            throw new \Exception('Unrecognized address format (should resemble 10.0.0.10/24)');
        }
        if ($gatewayRaw && !$gatewayIpAddress) {
            throw new \Exception('Unrecognized gateway format (should resemble 10.0.0.1)');
        }

        $this->linkService->configureLink($linkId, $mode, $deviceIpAddress, $gatewayIpAddress, $jumbo);

        print(LinkBackup::COMMIT_REQUIRED_NOTIFICATION_TEXT . PHP_EOL);
        return 0;
    }
}
