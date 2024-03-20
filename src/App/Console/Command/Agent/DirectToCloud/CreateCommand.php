<?php

namespace Datto\App\Console\Command\Agent\DirectToCloud;

use Datto\App\Console\Command\AbstractCommand;
use Datto\DirectToCloud\Creation\Service;
use Datto\Feature\FeatureService;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateCommand extends AbstractCommand
{
    protected static $defaultName = 'agent:directtocloud:create';

    /** @var Service */
    private $creationService;

    public function __construct(
        Service $creationService
    ) {
        parent::__construct();

        $this->creationService = $creationService;
    }

    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_ASSETS,
            FeatureService::FEATURE_DIRECT_TO_CLOUD_AGENTS
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Create a direct-to-cloud agent.')
            ->addOption('agent-uuid', null, InputOption::VALUE_REQUIRED, 'UUID of the agent.')
            ->addOption('hostname', null, InputOption::VALUE_REQUIRED, 'Fully Qualified Domain Name of the agent.')
            ->addOption('local-retention', null, InputOption::VALUE_REQUIRED, 'Local retention policy (eg. 0,1,2,3)')
            ->addOption('offsite-retention', null, InputOption::VALUE_REQUIRED, 'Offsite retention policy (eg. 0,1,2,3)')
            ->addOption('reseller-id', null, InputOption::VALUE_REQUIRED, 'Reseller that owns this agent.')
            ->addOption('use-existing-dataset', null, InputOption::VALUE_NONE, 'Expect the dataset to already exist.')
            ->addOption('has-subscription', null, InputOption::VALUE_NONE, 'Assets with subscriptions will replicate all recovery points.')
            ->addOption('operating-system', null, InputOption::VALUE_REQUIRED, 'Operating system of this asset');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentUuid = $input->getOption('agent-uuid');
        $hostname = $input->getOption('hostname');
        $localRetention = Service::deserializeRetentionForCommand($input->getOption('local-retention'));
        $offsiteRetention = Service::deserializeRetentionForCommand($input->getOption('offsite-retention'));
        $resellerId = $input->getOption('reseller-id');
        $useExistingDataset = (bool) $input->getOption('use-existing-dataset');
        $operatingSystem = $input->getOption('operating-system');

        if (!isset($agentUuid, $hostname, $resellerId, $operatingSystem)) {
            throw new InvalidArgumentException(
                'Command requires <agentUuid>, <hostname>, <resellerId>, <operatingSystem>'
            );
        }

        $agent = $this->creationService->createAgent(
            $agentUuid,
            $hostname,
            $localRetention,
            $offsiteRetention,
            $resellerId,
            $useExistingDataset,
            $operatingSystem
        );

        $output->writeln($agent->getKeyName());
        return 0;
    }
}
