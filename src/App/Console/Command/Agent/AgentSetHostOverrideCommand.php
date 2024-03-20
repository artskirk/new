<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentBackupKeyService;
use Datto\Asset\Agent\AgentService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to set the hostOverride keyfile, which defines the host (in samba/iscsi) during backups
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentSetHostOverrideCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:set:hostoverride';

    /** @var AgentBackupKeyService */
    private $agentBackupKeyService;

    public function __construct(
        AgentBackupKeyService $agentBackupKeyService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->agentBackupKeyService = $agentBackupKeyService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Set the hostOverride key')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent for which to set the hostOverride key')
            ->addArgument('hostOverride', InputArgument::OPTIONAL, 'The host to attempt to reach the agent from during backups (leave blank to unset)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set hostOverride key for all agents');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostOverride = $input->getArgument('hostOverride');
        $agents = $this->getAgents($input);
        foreach ($agents as $agent) {
            $output->writeln('Setting hostOverride to ' . $hostOverride . ' for ' . $agent->getDisplayName());
            $this->agentBackupKeyService->setHostOverrideKey($agent, $hostOverride);
        }
        return 0;
    }
}
