<?php


namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\CommandValidator;
use Datto\App\Security\Constraints\AssetExists;
use Datto\Asset\Agent\AgentService;
use Datto\Asset\AssetType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Force a particular asset to be flagged to have its partition table rewritten
 * on next backup. Because of how the live dataset is rolled back during the Backup transaction
 * any partition table changes made before the rollback stage will not be applied, so this command
 * flags a particular asset to have the asset table rewritten on next backup attempt.
 *
 */
class AgentPartitionRewriteCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:partition:rewrite';

    private CommandValidator $commandValidator;

    public function __construct(
        CommandValidator $commandValidator,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->commandValidator = $commandValidator;
    }


    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Flags an agent to have its partition table rewritten during next backup')
            ->addArgument(
                'keyName',
                InputOption::VALUE_REQUIRED,
                'The keyName of the agent that will have its partition table rewritten on next backup'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $asset = $input->getArgument('keyName');
        if ($asset) {
            $agent = $this->agentService->get($asset);
            //this will write a feature flag to /datto/config/keys/<uuid>.forcePartitionRewrite
            //that will force the partition table to be rewritten on next backup transaction
            $agent->setForcePartitionRewrite(true);
            $this->agentService->save($agent);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->commandValidator->validateValue(
            $input->getArgument('keyName'),
            new AssetExists(array('type' => AssetType::AGENT)),
            'Asset must exist'
        );
    }
}
