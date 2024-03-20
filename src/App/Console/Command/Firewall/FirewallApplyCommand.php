<?php

namespace Datto\App\Console\Command\Firewall;

use Datto\Service\Security\FirewallService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command applies the default firewall rules.
 *
 * @author Philipp Heckel <ph@datto.com>
 */
class FirewallApplyCommand extends Command
{
    protected static $defaultName = 'firewall:apply';

    private FirewallService $firewallService;

    public function __construct(
        FirewallService $firewallService
    ) {
        parent::__construct();

        $this->firewallService = $firewallService;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Configures the default firewall rules for this device.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-apply firewalld rules even if already applied.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption("force");
        $this->firewallService->apply($force);
        return 0;
    }
}
