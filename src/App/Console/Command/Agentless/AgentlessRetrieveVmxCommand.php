<?php

namespace Datto\App\Console\Command\Agentless;

use Datto\App\Console\Input\InputArgument;
use Datto\Asset\Agent\Agent;
use Datto\Asset\Agent\AgentService;
use Datto\Config\AgentConfigFactory;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Virtualization\VmwareApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retrieves the vmx (virtual machine definition) file from the
 * hypervisor. For debugging purposes.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentlessRetrieveVmxCommand extends Command
{
    protected static $defaultName = 'agentless:vmx:get';

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var VmwareApiClient */
    private $vmwareApiClient;

    /** @var AgentService */
    private $agentService;

    /** @var AgentConfigFactory */
    private $agentConfigFactory;

    public function __construct(
        EsxConnectionService $esxConnectionService,
        VmwareApiClient $vmwareApiClient,
        AgentService $agentService,
        AgentConfigFactory $agentConfigFactory
    ) {
        parent::__construct();

        $this->esxConnectionService = $esxConnectionService;
        $this->vmwareApiClient = $vmwareApiClient;
        $this->agentService = $agentService;
        $this->agentConfigFactory = $agentConfigFactory;
    }

    protected function configure()
    {
        $this
            ->setDescription('Retrieves the VMX file from the hypervisor')
            ->addArgument('agent', InputArgument::REQUIRED, 'Key name of the agent to get the vmx');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKeyName = $input->getArgument('agent');
        $agent = $this->agentService->get($agentKeyName);

        $esxInfo = $this->getEsxInfo($agent);
        $moRef = $esxInfo['moRef'];
        $connectionName = $esxInfo['connectionName'];

        $esxConnection = $this->esxConnectionService->get($connectionName);
        $vHost = $esxConnection->getEsxApi()->getVhost();

        $vmxData = $this->vmwareApiClient->retrieveVirtualMachineVmx($vHost, $moRef);

        $output->writeln($vmxData);
        return 0;
    }

    /**
     * @todo move this information to AgentlessSystem
     * @param Agent $agent
     * @return array The contents of the assetKey.esxInfo file.
     */
    private function getEsxInfo(Agent $agent)
    {
        $agentKey = $agent->getKeyName();
        $agentConfig = $this->agentConfigFactory->create($agentKey);
        $esxInfoRaw = $agentConfig->get('esxInfo');

        if ($esxInfoRaw === false) {
            throw new \Exception("Unable to read $agentKey.esxInfo");
        }

        $esxInfo = unserialize($esxInfoRaw, ['allowed_classes' => false]);

        if (!is_array($esxInfo)) {
            throw new \Exception("Error unserializing $agentKey.esxInfo");
        }

        return $esxInfo;
    }
}
