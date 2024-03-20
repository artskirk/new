<?php

namespace Datto\App\Console\Command\Agent\DirectToCloud;

use Datto\App\Console\Input\InputArgument;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\Asset\AssetService;

class SetMigrationInProgress extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:directtocloud:setmigrationinprogress';

    private $assetService;

    public function __construct(
        AgentService $agentService,
        AssetService $assetService
    ) {
        parent::__construct($agentService);
        $this->assetService = $assetService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS,
            FeatureService::FEATURE_PROTECTED_SYSTEM_CONFIGURABLE,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('set the migration in progress flag on an agent')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set for all agents')
            ->addArgument('agent', InputArgument::REQUIRED, 'The target agent')
            ->addArgument('isInProgress', InputArgument::REQUIRED, 'Whether or not migration is in progress');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);
        $isInProgress = strtolower($input->getArgument('isInProgress')) === 'true';

        foreach ($agents as $agent) {
            $agent->getLocal()->setMigrationInProgress($isInProgress);
            $this->assetService->save($agent);
        }
        return 0;
    }
}
