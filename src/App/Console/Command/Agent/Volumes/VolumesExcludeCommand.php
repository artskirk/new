<?php

namespace Datto\App\Console\Command\Agent\Volumes;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\VolumeInclusionService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Exception;

class VolumesExcludeCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:volumes:exclude';

    /** @var VolumeInclusionService */
    private $volumeInclusionService;

    /** @var FeatureService */
    private $featureService;

    public function __construct(
        AgentService $agentService,
        VolumeInclusionService $volumeInclusionService,
        FeatureService $featureService
    ) {
        parent::__construct($agentService);

        $this->volumeInclusionService = $volumeInclusionService;
        $this->featureService = $featureService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_AGENTS];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Set agent volumes as excluded from backup.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to exclude volume(s) for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Exclude volume(s) for all agents')
            ->addArgument(
                'guids',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Volume guid(s) to exclude.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $excludeGuids = $input->getArgument('guids');
        foreach ($this->getAgents($input) as $agent) {
            $this->volumeInclusionService->excludeGuids($agent, $excludeGuids);
        }
        return 0;
    }
}
