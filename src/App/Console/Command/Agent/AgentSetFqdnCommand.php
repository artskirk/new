<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentConnectivityService;
use Datto\Asset\Agent\AgentService;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AgentSetFqdnCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:set:fqdn';

    /** @var agentConnectivityService */
    private $agentConnectivityService;

    public function __construct(
        AgentConnectivityService $agentConnectivityService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->agentConnectivityService = $agentConnectivityService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Set the fqdn (Fully Qualified Domain Name) field in Agent Info')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent for which to set fqdn')
            ->addArgument('fqdn', InputArgument::REQUIRED, 'The fqdn to attempt to reach the agent at');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeyName = $input->getArgument('agent');
        if ($this->agentService->get($agentKeyName)->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents do not support changing fqdn.');
        }

        $fqdn = $input->getArgument('fqdn');
        $this->agentConnectivityService->retargetAgent($agentKeyName, $fqdn);
        return 0;
    }
}
