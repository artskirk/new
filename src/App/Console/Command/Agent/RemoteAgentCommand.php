<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\RemoteCommandService;
use Datto\Util\StringUtil;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a command on the agent.
 * @author Giovanni Carvelli <gcarvelli@datto.com>
 */
class RemoteAgentCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'command';

    /** @var RemoteCommandService */
    private $remoteCommandService;

    public function __construct(
        RemoteCommandService $remoteCommandService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->remoteCommandService = $remoteCommandService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Runs a command on the agent\'s host system')
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent key name.')
            ->addArgument('hostCommand', InputArgument::REQUIRED, 'Command to run.')
            ->addOption('arguments', null, InputOption::VALUE_OPTIONAL, 'Arguments for the command.', '')
            ->addOption('directory', null, InputOption::VALUE_OPTIONAL, 'Directory to run the command in.', '');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agent');
        if ($this->agentService->get($agentName)->getOriginDevice()->isReplicated()) {
            throw new Exception('Replicated agents do not support running commands.');
        }

        $hostCommand = $input->getArgument('hostCommand');
        $arguments = $input->getOption('arguments');
        $hostArgs = StringUtil::splitArguments($arguments);
        $hostDir = $input->getOption('directory');

        $output->writeln(
            $this->remoteCommandService
                ->runCommand($agentName, $hostCommand, $hostArgs, $hostDir)
                ->getOutput()
        );
        return 0;
    }
}
