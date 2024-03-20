<?php

namespace Datto\App\Console\Command\Firewall;

use Datto\App\Console\Input\InputArgument;
use Datto\Service\Security\FirewallService;
use Datto\Utility\Firewall\FirewallCmd;
use Datto\Utility\Firewall\FirewalldUserOverrideManager;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command closes a port in the firewall.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FirewallCloseCommand extends Command
{
    protected static $defaultName = 'firewall:close';

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
            ->setDescription('Closes a port in firewalld zone.')
            ->addArgument('port', InputArgument::REQUIRED, 'Port')
            ->addArgument('save', InputArgument::REQUIRED, 'Should the change persist across reboots (true/false).')
            ->addArgument('zone', InputArgument::OPTIONAL, 'firewalld-zone', 'datto')
            ->addArgument('protocol', InputArgument::OPTIONAL, 'Protocol - tcp or udp', FirewallCmd::PROTOCOL_TCP);
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $port = $input->getArgument('port');
        $zone = $input->getArgument('zone');
        $protocol = $input->getArgument('protocol');
        $save = strtolower($input->getArgument('save'));
        $isPermanent = ($save === 'true' || $save === 'yes');

        if (!preg_match('/^\d+$/', $port)) {
            throw new Exception('Invalid port. Must be numeric.');
        }

        if ($isPermanent) {
            $this->firewallService->close((int)$port, $zone, $protocol, true);
            $this->firewalldUserOverrideManager->removeOverridePort($zone, "$port/$protocol");
        } else {
            $this->firewallService->close((int)$port, $zone, $protocol, false);
        }

        $output->writeln("Removed port: " . $port .
            " from zone: " . $zone . " protocol: " . $protocol .
            " persist across reboots: " . ($isPermanent ? "yes" : "no"));

        return 0;
    }
}
