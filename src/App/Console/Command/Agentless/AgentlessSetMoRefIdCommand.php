<?php

namespace Datto\App\Console\Command\Agentless;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Agentless\RetargetAgentlessService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retarget an agentless system.
 * @author Peter Geer <pgeer@datto.com>
 * @codeCoverageIgnore
 */
class AgentlessSetMoRefIdCommand extends Command
{
    protected static $defaultName = 'agentless:set:morefid';

    protected function configure()
    {
        $this
            ->setDescription('Retarget an agentless system to a new MoRefID')
            ->addArgument('agent', InputArgument::REQUIRED, 'Key name of the agent to retarget')
            ->addArgument('connection', InputArgument::REQUIRED, 'Name of the hypervisor connection to use')
            ->addArgument('morefid', InputArgument::REQUIRED, 'The new MoRefID to use for the agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeyName = $input->getArgument('agent');
        $connectionName = $input->getArgument('connection');
        $moRefId = $input->getArgument('morefid');

        $retargetAgentlessService = new RetargetAgentlessService($agentKeyName);
        $retargetAgentlessService->retarget($moRefId, $connectionName);
        return 0;
    }
}
