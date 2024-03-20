<?php

namespace Datto\App\Console\Command\Agent;

use Datto\Asset\Agent\Windows\Serializer\WindowsServicesSerializer;
use Datto\Asset\Agent\Windows\WindowsService;
use Datto\Asset\Agent\Windows\WindowsServiceRetriever;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Returns a list of services that are running on the agent side.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class AgentServicesListCommand extends Command
{
    protected static $defaultName = 'agent:services';

    /** @var WindowsServiceRetriever */
    private $windowsServiceRetriever;

    /** @var WindowsServicesSerializer */
    private $windowsServicesSerializer;

    public function __construct(
        WindowsServiceRetriever $windowsServiceRetriever,
        WindowsServicesSerializer $windowsServicesSerializer
    ) {
        parent::__construct();

        $this->windowsServiceRetriever = $windowsServiceRetriever;
        $this->windowsServicesSerializer = $windowsServicesSerializer;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Returns a list of services that are currently running on the agent')
            ->addArgument('agent', InputArgument::REQUIRED, 'Agent key name.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the service list in json format.')
            ->addOption('cached', null, InputOption::VALUE_NONE, 'Use the locally cached running service list.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentKey = $input->getArgument('agent');
        $json = $input->getOption('json');
        $cached = $input->getOption('cached');

        if ($cached) {
            $windowsServices = $this->windowsServiceRetriever->getCachedRunningServices($agentKey);
        } else {
            $windowsServices = $this->windowsServiceRetriever->refreshCachedRunningServices($agentKey);
        }

        if ($json) {
            $this->renderJson($output, $windowsServices);
        } else {
            $this->renderHuman($output, $windowsServices);
        }
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param WindowsService[] $windowsServices
     */
    private function renderHuman(OutputInterface $output, array $windowsServices): void
    {
        $table = new Table($output);
        $table->setHeaders(['Display Name', 'Service Name']);

        foreach ($windowsServices as $windowsService) {
            $table->addRow([$windowsService->getDisplayName(), $windowsService->getServiceName()]);
        }

        $table->render();
    }

    /**
     * @param OutputInterface $output
     * @param WindowsService[] $windowsServices
     */
    private function renderJson(OutputInterface $output, array $windowsServices): void
    {
        $output->writeln(json_encode($this->windowsServicesSerializer->serialize($windowsServices)));
    }
}
