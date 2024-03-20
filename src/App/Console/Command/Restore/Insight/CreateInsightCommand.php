<?php

namespace Datto\App\Console\Command\Restore\Insight;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\AgentService;
use Datto\Restore\Insight\InsightsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create backup insight on an asset between two points
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class CreateInsightCommand extends Command
{
    protected static $defaultName = 'restore:insight:create';

    /** @var AgentService */
    private $agentService;

    /** @var InsightsService */
    private $insightsService;

    public function __construct(
        InsightsService $insightsService,
        AgentService $agentService
    ) {
        parent::__construct();

        $this->insightsService = $insightsService;
        $this->agentService = $agentService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument("agent", InputArgument::REQUIRED)
            ->addArgument("firstPoint", InputArgument::REQUIRED)
            ->addArgument("secondPoint", InputArgument::REQUIRED)
            ->setDescription("Creates a backup insight between the two points for the given asset");
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKey = $input->getArgument("agent");
        $first = $input->getArgument("firstPoint");
        $second = $input->getArgument("secondPoint");

        $this->insightsService->start($agentKey, $first, $second, false);
        return 0;
    }
}
