<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractAgentCommand extends AbstractCommand
{
    /** @var AgentService */
    protected $agentService;

    public function __construct(
        AgentService $agentService
    ) {
        parent::__construct();

        $this->agentService = $agentService;
    }

    public static function getRequiredFeatures(): array
    {
        return [];
    }

    protected function configureGetAgents(): void
    {
        $this
            ->addArgument('agent', InputArgument::OPTIONAL, 'Specific agent to perform the action on')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Perform the action on all agents');
    }

    /**
     * @return Agent[]
     */
    protected function getAgents(InputInterface $input)
    {
        $agent = $input->getArgument('agent');
        $all = $input->getOption('all');

        if ($agent === null && $all === false) {
            throw new InvalidArgumentException('Must specify an agent or --all.');
        }
        if ($agent !== null && $all === true) {
            throw new InvalidArgumentException('Must specify either an agent or --all, not both.');
        }

        $agents = $all ? $this->agentService->getAll() : [$this->agentService->get($agent)];

        return $agents;
    }
}
