<?php

namespace Datto\App\Console\Command\Firewall;

use Datto\App\Console\Input\InputArgument;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Firewall\FirewalldUserOverrideManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command adds a firewalld service to firewalld.
 *
 * @author Alex Joseph <ajoseph@datto.com>
 */
class FirewallAddServiceCommand extends Command
{
    protected static $defaultName = 'firewall:add:service';

    private FirewallService $firewallService;
    private FirewalldUserOverrideManager $firewalldUserOverrideManager;

    public function __construct(
        FirewallService $firewallService,
        FirewalldUserOverrideManager $firewalldUserOverrideManager
    ) {
        parent::__construct();

        $this->firewallService = $firewallService;
        $this->firewalldUserOverrideManager = $firewalldUserOverrideManager;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Adds a firewalld service to firewalld zone.')
            ->addArgument('service', InputArgument::REQUIRED, 'firewalld service')
            ->addArgument('save', InputArgument::REQUIRED, 'Should the change persist across reboot (true/false).')
            ->addArgument('zone', InputArgument::OPTIONAL, 'firewalld zone', 'datto');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = $input->getArgument('service');
        $zone = $input->getArgument('zone');
        $save = strtolower($input->getArgument('save'));
        $isPermanent = ($save === 'true' || $save === 'yes');

        if ($isPermanent) {
            $this->firewallService->openService($service, $zone, true);
            $this->firewalldUserOverrideManager->addOverrideService($zone, $service);
        } else {
            $this->firewallService->openService($service, $zone, false);
        }

        $output->writeln("Adding service: " . $service .
            " to zone: " . $zone . " persist across reboots: " . ($isPermanent ? "yes" : "no") . ".");

        return 0;
    }
}
