<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Verification\Screenshot\ScreenshotAlertService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks screenshots of agents for a device
 *
 * @author Mike Micatka <mmicatka@datto.com>
 */
class AgentScreenshotCheckTimeCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:verification:screenshot:checkTime';

    /** @var ScreenshotAlertService */
    private $screenshotAlertService;

    public function __construct(
        ScreenshotAlertService $screenshotAlertService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->screenshotAlertService = $screenshotAlertService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Checks the screenshot times per agent and sends alert email if out of threshold')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to check the screenshot times of')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check the screenshot times of all agents');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        foreach ($agents as $agent) {
            $this->screenshotAlertService->checkScreenshotTimeByAgent($agent);
        }
        return 0;
    }
}
