<?php

namespace Datto\App\Console\Command\Agent\Rescue;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Rescue\RescueAgentService;
use Datto\Connection\Libvirt\KvmConnection;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Utility\Security\SecretString;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a rescue agent.
 *
 * @author Christopher R. Wicks <cwicks@datto.com>
 */
class CreateRescueAgentCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:rescue:create';

    /** @var RescueAgentService */
    private $rescueAgentService;

    public function __construct(
        RescueAgentService $rescueAgentService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->rescueAgentService = $rescueAgentService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a new rescue agent from the source agent snapshot')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent from which to create a rescue agent')
            ->addOption('passphrase', null, InputOption::VALUE_REQUIRED, 'The encryption passphrase for the agent, if needed')
            ->addOption('pause', null, InputOption::VALUE_NONE, 'Pause the source agent')
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'The snapshot to use for the rescue agent; the most recent snapshot will be used if none is provided'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeyName = $input->getArgument('agent');
        $snapshot = $input->getOption('snapshot');
        $pause = $input->getOption('pause') ?: false;
        $connectionName = KvmConnection::CONNECTION_NAME;
        $passphrase = new SecretString($input->getOption('passphrase'));

        if (!$snapshot) {
            $agent = $this->agentService->get($agentKeyName);
            $snapshot = $agent->getLocal()->getRecoveryPoints()->getLast()->getEpoch();
        }
        $this->rescueAgentService->create($agentKeyName, $snapshot, $pause, $connectionName, false, $passphrase);
        return 0;
    }
}
