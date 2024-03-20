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

class VolumesIncludeCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:volumes:include';

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
            ->setDescription('Set agent volumes as included in backup.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent for which to include volume(s) for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Include volume(s) for all agents.')
            ->addArgument(
                'guids',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Volume guid(s) to include.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $includeGuids = $input->getArgument('guids');
        foreach ($this->getAgents($input) as $agent) {
            if ($agent->isDirectToCloudAgent() && !$this->featureService->isSupported(FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS_MULTI_VOLUME)) {
                if (count($agent->getIncludedVolumesSettings()->getIncludedList()) > 0) {
                    throw new Exception('There are already volumes included and multi volume support is not enabled for DCC agents.');
                }
            }

            $this->volumeInclusionService->includeGuids($agent, $includeGuids);
        }
        return 0;
    }
}
