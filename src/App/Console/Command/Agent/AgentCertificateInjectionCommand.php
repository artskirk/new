<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Certificate\CertificateUpdateService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Injects a new root CA into a shadowsnap agent
 *
 * @author Matthew Cheman <mcheman@datto.com>
 */
class AgentCertificateInjectionCommand extends AbstractAgentCommand
{
    protected static $defaultName = 'agent:cert:inject';

    /** @var CertificateUpdateService */
    private $certificateUpdateService;

    public function __construct(
        CertificateUpdateService $certificateUpdateService,
        AgentService $agentService
    ) {
        parent::__construct($agentService);

        $this->certificateUpdateService = $certificateUpdateService;
    }

    protected function configure()
    {
        $this
            ->setDescription('Injects a new root CA into shadowsnap agents, and updates the latest working cert for all agent types')
            ->addArgument('agent', InputArgument::OPTIONAL, 'The agent to inject the new cert into, and update the latest working cert for')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Inject the cert into all shadowsnap agents, and updates the latest working cert for all agents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->getAgents($input);

        $this->certificateUpdateService->updateAgentCertificates($agents);
        return 0;
    }
}
