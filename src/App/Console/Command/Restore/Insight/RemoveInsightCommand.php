<?php

namespace Datto\App\Console\Command\Restore\Insight;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Restore\Insight\InsightsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remove backup insight for agent
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class RemoveInsightCommand extends Command
{
    protected static $defaultName = 'restore:insight:remove';

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
            ->addArgument(
                "agent",
                InputArgument::OPTIONAL,
                "Agent's asset ID. Required unless --all is specified."
            )
            ->addOption(
                "all",
                null,
                InputOption::VALUE_NONE,
                "Remove all active insights."
            )
            ->setDescription("Removes the backup insight for the given asset");
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeys = $this->getAgentKeys($input);

        foreach ($agentKeys as $agentKey) {
            $output->writeln("Removing insight for agent $agentKey");
            $this->insightsService->remove($agentKey);
        }

        return 0;
    }

    /**
     * @return string[]
     */
    private function getAgentKeys(InputInterface $input): array
    {
        $all = $input->getOption('all');
        /** @var string $agentKey */
        $agentKey = $input->getArgument('agent');

        $noneOrBoth = (!$all && !$agentKey) || ($all && $agentKey);
        if ($noneOrBoth) {
            throw new \RuntimeException('Either agent ID or --all must be specified.');
        }

        if (!$all) {
            return [$agentKey];
        }

        $agentIds = array_map(
            fn (Agent $agent) => $agent->getKeyName(),
            $this->agentService->getAll()
        );
        return $agentIds;
    }
}
