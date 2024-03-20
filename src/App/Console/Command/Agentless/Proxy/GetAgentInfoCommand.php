<?php

namespace Datto\App\Console\Command\Agentless\Proxy;

use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fetch agentless system info via proxy
 *
 * @author Mario Rial <mrial@datto.com>
 */
class GetAgentInfoCommand extends Command
{
    protected static $defaultName = 'agentless:proxy:agent:info';

    /** @var AgentlessSessionService */
    private $agentlessSessionService;

    public function __construct(
        AgentlessSessionService $agentlessSessionService
    ) {
        parent::__construct();

        $this->agentlessSessionService = $agentlessSessionService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Get agent info')
            ->addOption(
                'agentless-session',
                null,
                InputOption::VALUE_REQUIRED,
                'Agentless session id returned by initialize'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentlessSessionId = $input->getOption('agentless-session');

        $output->writeln([
            "Retrieving agent info for session $agentlessSessionId ...",
            '============',
        ]);

        $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
        $agentlessSession = $this->agentlessSessionService->getSession($sessionId);

        $esxInfo = $agentlessSession->getEsxVmInfo();
        $output->writeln("ESX-Agentless info: ");
        $output->writeln(json_encode($esxInfo, JSON_PRETTY_PRINT));

        $agentInfo = $agentlessSession->getAgentVmInfo();
        $output->writeln("Agent info: ");
        $output->writeln(json_encode($agentInfo, JSON_PRETTY_PRINT));
        return 0;
    }
}
