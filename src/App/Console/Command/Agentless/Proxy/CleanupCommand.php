<?php

namespace Datto\App\Console\Command\Agentless\Proxy;

use Datto\Agentless\Proxy\AgentlessSessionId;
use Datto\Agentless\Proxy\AgentlessSessionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cleanup agentless proxy session
 *
 * @author Andrew Cope <acope@datto.com>
 */
class CleanupCommand extends Command
{
    protected static $defaultName = 'agentless:proxy:cleanup';

    /** @var AgentlessSessionService */
    private $agentlessSessionService;

    public function __construct(AgentlessSessionService $agentlessSessionService)
    {
        parent::__construct();
        
        $this->agentlessSessionService = $agentlessSessionService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Cleans up agentless proxy session')
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
        $sessionId = AgentlessSessionId::fromString($agentlessSessionId);
        $this->agentlessSessionService->cleanupSession($sessionId);
        return 0;
    }
}
