<?php

namespace Datto\App\Console\Command\Network;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to configure DNS settings on the device, and specify a search domain.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class ConfigureDnsCommand extends AbstractNetworkCommand
{
    protected static $defaultName = 'network:configure:dns';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription("Change your dns settings")
            ->addOption(
                "dns1",
                null,
                InputOption::VALUE_REQUIRED,
                "Primary Domain Name Server"
            )
            ->addOption(
                "dns2",
                null,
                InputOption::VALUE_OPTIONAL,
                "Secondary Domain Name Server"
            )
            ->addOption(
                "dns3",
                null,
                InputOption::VALUE_OPTIONAL,
                "Tertiary Domain Name Server"
            )
            ->addOption(
                "domain",
                "s",
                InputOption::VALUE_OPTIONAL,
                "Specify your search domains (separated with spaces)"
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dns1 = $input->getOption("dns1");
        $dns2 = $input->getOption("dns2");
        $dns3 = $input->getOption("dns3");
        $domain = $input->getOption("domain");

        $this->networkService->updateGlobalDns(array_filter([$dns1, $dns2, $dns3]), explode(' ', $domain));
        return 0;
    }
}
