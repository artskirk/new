<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentBackupKeyService;
use Datto\Asset\Agent\AgentService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * snapctl command to set the forceFull flag, which forces next backup to be a full
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class AgentSetForceFullCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:set:forcefull';

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
            ->setDescription('Set the forceFull key (forces a full next backup)')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent for which to set the forceFull key')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set forceFull key for all agents');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);
        foreach ($agents as $agent) {
            if ($agent->getOriginDevice()->isReplicated()) {
                continue;
            }
            $output->writeln('Setting forceFull flag for ' . $agent->getDisplayName());
            $this->agentBackupKeyService->setForceFullKey($agent);
        }
        return 0;
    }
}
