<?php

namespace Datto\App\Console\Command\Agent;

use Datto\App\Console\Command\Agent\AbstractAgentCommand;
use Datto\Asset\Agent\AgentService;
use Datto\App\Console\Input\InputArgument;
use Datto\Asset\BackupConstraintsService;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AgentBackupConstraintsValidateCommand extends AbstractAgentCommand
{
    const TABLE_HEADERS = [
        'Agent UUID',
        'Validation Message'
    ];

    protected static $defaultName = 'agent:backup:constraints:validate';

    /** @var BackupConstraintsService */
    private $backupConstraintsService;

    /**
     * @inheritdoc
     */
    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_AGENT_BACKUP_CONSTRAINTS
        ];
    }

    /**
     * @param BackupConstraintsService $backupConstraintsService
     */
    public function __construct(
        AgentService $agentService,
        BackupConstraintsService $backupConstraintsService
    ) {
        parent::__construct($agentService);
        $this->backupConstraintsService = $backupConstraintsService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Validates a given agent backup constraints. If feature is supported.')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The target agent for validation.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Validate all agents.');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);
        $table = new Table($output);

        $table->setHeaders(self::TABLE_HEADERS);

        foreach ($agents as $agent) {
            $result = $this->backupConstraintsService->enforce($agent, false);

            $table->addRow([
                $agent->getKeyName(),
                $result->getMaxTotalVolumeMessage()
            ]);
        }

        $table->render();
        return 0;
    }
}
