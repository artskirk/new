<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Restore\Virtualization\AgentVmManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetConnectionInfoCommand extends AbstractCommand
{
    protected static $defaultName = 'virtualization:remote:details';

    /** @var AgentVmManager */
    private $agentVmManager;

    public function __construct(
        AgentVmManager $agentVmManager
    ) {
        parent::__construct();

        $this->agentVmManager = $agentVmManager;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION];
    }

    public function configure()
    {
        $this
            ->setDescription('Get VNC connection information for a virt')
            ->addArgument('agentName', InputArgument::REQUIRED, 'Agent with a running virt');
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agentName');

        if (!$this->agentVmManager->vmExists($agentName)) {
            throw new \Exception('VM for this agent does not exist.');
        }

        $connectionDetails = json_encode([
            'vnc' => $this->agentVmManager->getVncConnectionDetails($agentName)
        ], JSON_PRETTY_PRINT);

        $output->writeln($connectionDetails);
        return 0;
    }
}
