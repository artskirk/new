<?php
namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\ArchiveService;
use Datto\App\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AgentArchiveCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:archive';

    /** @var ArchiveService */
    private $archiveService;

    public function __construct(
        ArchiveService $archiveService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->archiveService = $archiveService;
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Archive given agent.')
            ->addArgument('agent', InputArgument::REQUIRED, 'The agent to archive');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agent = $input->getArgument('agent');
        $this->archiveService->archive($agent);
        return 0;
    }
}
