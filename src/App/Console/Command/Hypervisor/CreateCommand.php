<?php

namespace Datto\App\Console\Command\Hypervisor;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Connection\ConnectionType;
use Datto\Connection\Libvirt\AbstractLibvirtConnection as Connection;
use Datto\Connection\Service\ConnectionService;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Connection\Service\HvConnectionService;
use Datto\Feature\FeatureService;
use Datto\Utility\Security\SecretString;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Create a new hypervisor connection.
 *
 * @author Stephen Allan <sallan@datto.com>
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class CreateCommand extends AbstractCommand
{
    private const SUPPORTED_HYPERVISOR_TYPES = [
        ConnectionType::LIBVIRT_HV,
        ConnectionType::LIBVIRT_ESX,
    ];

    protected static $defaultName = 'hypervisor:create';

    private ConnectionService $connectionService;
    private HvConnectionService $hvConnectionService;
    private EsxConnectionService $esxConnectionService;

    private InputInterface $input;
    private OutputInterface $output;

    public function __construct(
        ConnectionService $connectionService,
        HvConnectionService $hvConnectionService,
        EsxConnectionService $esxConnectionService
    ) {
        parent::__construct();

        $this->connectionService = $connectionService;
        $this->hvConnectionService = $hvConnectionService;
        $this->esxConnectionService = $esxConnectionService;
    }

    /**
     * @inheritdoc
     */
    public static function getRequiredFeatures(): array
    {
        return [
            FeatureService::FEATURE_HYPERVISOR_CONNECTIONS
        ];
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Create a new hypervisor connection')
            ->addArgument('type', InputArgument::REQUIRED, 'Type of hypervisor connection (hyperv, esx)')
            ->addArgument('name', InputArgument::REQUIRED, 'Name of hypervisor connection')
            ->addArgument('server', InputArgument::REQUIRED, 'Hypervisor DNS name or IP')
            ->addArgument('username', InputArgument::REQUIRED, 'Connection username')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Optional Hyper-V login domain')
            ->addOption('https', null, InputOption::VALUE_NONE, 'Hyper-V connection should use HTTPS instead of HTTP')
            ->addOption('primary', null, InputOption::VALUE_NONE, 'Offload screenshots over this connection')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'Offload method for ESX hosts (nfs, iscsi)')
            ->addOption(
                'hba',
                null,
                InputOption::VALUE_REQUIRED,
                'HBA name (required for ESX hosts with iSCSI offload)'
            )
            ->addOption(
                'datastore',
                null,
                InputOption::VALUE_REQUIRED,
                'Datastore (required for ESX hosts with iSCSI offload)'
            )
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $type = $input->getArgument('type');
        $name = $input->getArgument('name');

        if (null !== $this->connectionService->get($name)) {
            throw new Exception("Cannot create connection named '$name' as it already exists.");
        }

        if (!in_array($type, self::SUPPORTED_HYPERVISOR_TYPES)) {
            throw new Exception(
                "Invalid hypervisor type. Supported values are: "
                .implode(', ', self::SUPPORTED_HYPERVISOR_TYPES)
            );
        }

        switch ($type) {
            case ConnectionType::LIBVIRT_HV:
                $connection = $this->createHyperVConnection($name);
                break;
            case ConnectionType::LIBVIRT_ESX:
                $connection = $this->createEsxConnection($name);
                break;
            default:
                throw new \LogicException('Unsupported hypervisor type');
        }

        if ($input->getOption('primary')) {
            $this->connectionService->setAsPrimary($connection);
        }

        return self::SUCCESS;
    }

    public function createHyperVConnection(string $name): Connection
    {
        $password = $this->promptForPassword();

        $params = $this->input->getArguments();
        $params['password'] = $password;
        $params['http'] = $this->input->getOption('https') !== true;

        try {
            $connection = $this->hvConnectionService->createAndConfigure($name, $params);
            $connection->saveData();
        } catch (Throwable $throwable) {
            throw new Exception("Could not connect to hypervisor with the provided information.", 0, $throwable);
        }

        return $connection;
    }

    private function createEsxConnection(string $name): Connection
    {
        $offloadMethod = $this->input->getOption('method');
        $hba = $this->input->getOption('hba');
        $datastore = $this->input->getOption('datastore');
        $this->validateOffloadMethodAndHba($offloadMethod, $hba, $datastore);

        $password = $this->promptForPassword();

        $apiType = $this->esxConnectionService->getApiType(
            $this->input->getArgument('server'),
            $this->input->getArgument('username'),
            new SecretString($password)
        );
        $this->logger->debug("HCC0001 API type is $apiType");

        if ($apiType !== 'HostAgent') {
            throw new \RuntimeException('Only adding standalone ESX hosts is supported currently');
        }

        try {
            $connection = $this->esxConnectionService->create($name);
            $this->esxConnectionService->setConnectionParams($connection, [
                'offloadMethod' => $hba ?? $offloadMethod,  // looks unusual, but that's what this method expects
                'datastore' => $datastore,
                'username' => $this->input->getArgument('username'),
                'password' => $password,
                'hostType' => 'stand-alone',
                'server' => $this->input->getArgument('server'),
            ]);
            $connection->saveData();
        } catch (Throwable $throwable) {
            throw new Exception("Could not connect to hypervisor with the provided information.", 0, $throwable);
        }

        return $connection;
    }

    private function validateOffloadMethodAndHba(?string $offloadMethod, ?string $hba, ?string $datastore): void
    {
        if ($offloadMethod === null) {
            throw new Exception('--method is required for ESX hosts');
        } elseif ($offloadMethod === 'nfs') {
            if ($hba !== null) {
                throw new \RuntimeException('--hba is only allowed for iSCSI offload method');
            }
        } elseif ($offloadMethod === 'iscsi') {
            if ($hba === null) {
                throw new \RuntimeException('--hba is required for iSCSI offload method');
            }
            if ($datastore === null) {
                throw new \RuntimeException('--datastore is required for iSCSI offload method');
            }
        } else {
            throw new Exception('Invalid offload method. Supported values are: iscsi, nfs');
        }
    }

    private function promptForPassword(): string
    {
        return $this->promptPassphrase(
            $this->input,
            $this->output,
            'Enter hypervisor connection password: '
        );
    }
}
