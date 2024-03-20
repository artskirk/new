<?php

namespace Datto\App\Console\Command\Firewall;

use Datto\App\Console\Input\InputArgument;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Firewall\FirewalldUserOverrideManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command removes a firewalld service from firewalld.
 *
 * @author Alex Joseph <ajoseph@datto.com>
 */
class FirewallRemoveServiceCommand extends Command
{
    protected static $defaultName = 'firewall:remove:service';

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
            ->setDescription('Removes a firewalld service from firewalld zone.')
            ->addArgument('service', InputArgument::REQUIRED, 'firewalld service')
            ->addArgument('save', InputArgument::REQUIRED, 'Should the change persist across reboot (true/false).')
            ->addArgument('zone', InputArgument::OPTIONAL, 'firewalld-zone', 'datto');
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
            $this->firewallService->closeService($service, $zone, true);
            $this->firewalldUserOverrideManager->removeOverrideService($zone, $service);
        } else {
            $this->firewallService->closeService($service, $zone, false);
        }

        $output->writeln("Removing service: " . $service . " from zone: " . $zone
            . " persist across reboots: " . ($isPermanent ? "yes" : "no") . ".");

        return 0;
    }
}
