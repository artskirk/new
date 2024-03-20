<?php

namespace Datto\App\Console\Command\Agent;

use DateTime;
use Datto\Asset\Agent\AgentBootTimeService;
use Datto\Asset\Agent\AgentService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retrieves the most recent boot time from a Windows agent
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class AgentBootTimeCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:boottime';

    /** @var AgentBootTimeService */
    private $agentBootTimeService;

    public function __construct(
        AgentBootTimeService $agentBootTimeService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->agentBootTimeService = $agentBootTimeService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Retrieves the most recent boot time from a Windows agent')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to retrieve the latest boot time from')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Retrieve the latest boot time from all agents on this device')
            ->addOption('no-event', null, InputOption::VALUE_NONE, 'Do not send an event to device-web with the updated boot time');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        $sendEvent = !$input->getOption('no-event');

        foreach ($agents as $agent) {
            $this->logger->setAssetContext($agent->getKeyName());
            $bootTime = $this->agentBootTimeService->retrieveLastSystemBootTime($agent);

            $this->logger->info('BTC0001 Last system boot time of agent.', ['bootTime' => $bootTime->format(DateTime::ATOM)]);

            if ($sendEvent) {
                $this->agentBootTimeService->sendBootTimeEvent($agent, $bootTime, $this->logger);
            }
        }
        return 0;
    }
}
