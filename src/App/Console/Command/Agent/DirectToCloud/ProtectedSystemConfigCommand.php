<?php

namespace Datto\App\Console\Command\Agent\DirectToCloud;

use Datto\App\Console\Input\InputArgument;
use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\DirectToCloud\ProtectedSystemConfigurationService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jess Gentner <jgentner@datto.com>
 */
class ProtectedSystemConfigCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:directtocloud:config:set';

    /** @var ProtectedSystemConfigurationService */
    private $configurationService;

    public function __construct(
        ProtectedSystemConfigurationService $configurationService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->configurationService = $configurationService;
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
            ->setDescription('Set the given configuration for the agent running on the protected machine')
            ->addArgument('configuration', InputArgument::REQUIRED, 'The json encoded configuration')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The target agent')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set for all agents')
            ->addOption('merge', null, InputOption::VALUE_NONE, 'merge configuration request state')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'override configuration request state');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);
        $configuration = $input->getArgument('configuration');

        if ($input->getOption('merge')) {
            $this->configurationService->merge($agents, $configuration);
        } elseif ($input->getOption('overwrite')) {
            $this->configurationService->overwrite($agents, $configuration);
        } else {
            $this->configurationService->set($agents, $configuration);
        }
        return 0;
    }
}
