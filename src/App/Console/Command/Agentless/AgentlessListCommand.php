<?php

namespace Datto\App\Console\Command\Agentless;

use Datto\Connection\Libvirt\AbstractLibvirtConnection;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Agentless\EsxVirtualMachineManager;
use Datto\Connection\Service\ConnectionService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to list the available systems for agentless pairing
 * @author Peter Geer <pgeer@datto.com>
 */
class AgentlessListCommand extends Command
{
    protected static $defaultName = 'agentless:list';

    /** @var ConnectionService */
    private $connectionService;

    /** @var EsxVirtualMachineManager */
    private $esxVirtualMachineManager;

    public function __construct(
        ConnectionService $connectionService,
        EsxVirtualMachineManager $esxVirtualMachineManager
    ) {
        parent::__construct();

        $this->connectionService = $connectionService;
        $this->esxVirtualMachineManager = $esxVirtualMachineManager;
    }

    protected function configure()
    {
        $this
            ->setDescription('List the VMs available for pairing on each hypervisor connection')
            ->addOption('list-connections', 'l', InputOption::VALUE_NONE, 'List the available connections')
            ->addOption('connection', 'c', InputOption::VALUE_OPTIONAL, 'The connection for which to list VMs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('list-connections')) {
            $this->listConnections($output);
        } else {
            $this->listVms($input, $output);
        }
        return 0;
    }

    /**
     * Display the list of VMs, either for a single connection or for all of them.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    private function listVms(InputInterface $input, OutputInterface $output): void
    {
        $connectionName = $input->getOption('connection');
        if ($connectionName) {
            $this->listSingleConnection($connectionName, $output);
        } else {
            $this->listAllConnections($output);
        }
    }

    /**
     * Display the name and moRefID for each VM on a single connection.
     *
     * @param string $connectionName
     * @param OutputInterface $output
     */
    private function listSingleConnection(string $connectionName, OutputInterface $output)
    {
        $connection = $this->getConnection($connectionName);

        try {
            /*
             * Suppressing notices and warnings when a Host cannot be reached because they occur in Vmwarephp which is
             * an external library.
             */
            $vmList = @$this->getConnectionVms($connection);
        } catch (\Throwable $throwable) {
            $output->writeln('Unable to connect to: ' . $connection->getName());
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['VM Name', 'MoRefID']);
        foreach ($vmList as $vm) {
            $table->addRow([$vm['name'], $vm['moRef']]);
        }
        $table->render();
    }

    /**
     * Display a list of connection name, VM name, and moRefID for all connections.
     *
     * @param OutputInterface $output
     */
    private function listAllConnections(OutputInterface $output): void
    {
        $connections = $this->getValidConnections();
        $missingConnections = [];
        $table = new Table($output);
        $table->setHeaders(['Connection', 'VM Name', 'MoRefID']);
        foreach ($connections as $connection) {
            try {
                /*
                 * Suppressing notices and warnings when a Host cannot be reached because they occur in Vmwarephp
                 * which is an external library.
                 */
                $vmList = @$this->getConnectionVms($connection);
            } catch (\Throwable $throwable) {
                $missingConnections[] = $connection->getName();
                continue;
            }

            foreach ($vmList as $vm) {
                $table->addRow([$connection->getName(), $vm['name'], $vm['moRef']]);
            }
        }
        $table->render();

        if (!empty($missingConnections)) {
            $output->writeln('Unable to connect to: ' . implode(', ', $missingConnections));
        }
    }

    /**
     * Display a list of the available hypervisor connections
     *
     * @param OutputInterface $output
     */
    private function listConnections(OutputInterface $output): void
    {
        $connections = $this->getValidConnections();
        foreach ($connections as $connection) {
            $output->writeln($connection->getName());
        }
    }

    /**
     * Get and validate a connection from a name.
     *
     * @param string $connectionName
     * @return AbstractLibvirtConnection
     */
    private function getConnection(string $connectionName): AbstractLibvirtConnection
    {
        $connection = $this->connectionService->get($connectionName);
        if (!$connection) {
            throw new Exception("Could not get connection '$connectionName''");
        }
        if (!$connection->isEsx()) {
            throw new Exception("Only ESX connections are supported");
        }
        return $connection;
    }

    /**
     * Get all valid connections.
     *
     * @return array
     */
    private function getValidConnections(): array
    {
        $connections = $this->connectionService->getAll();
        $callback = function ($connection) {
            return $connection->isEsx();
        };
        return array_filter($connections, $callback);
    }

    /**
     * Get all the available VMs for a given connection.
     *
     * @param EsxConnection $connection
     * @return array
     */
    private function getConnectionVms(EsxConnection $connection): array
    {
        $vms = $this->esxVirtualMachineManager->getAvailableVirtualMachinesForConnection($connection->getName());
        $vmList = $vms ? $vms[0]['VMs'] : [];
        return $vmList;
    }
}
