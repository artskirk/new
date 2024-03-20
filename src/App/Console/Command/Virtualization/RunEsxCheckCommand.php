<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Service\ConnectionService;
use Datto\Log\LoggerAwareTrait;
use Datto\System\Hardware;
use Datto\Config\DeviceConfig;
use Datto\Feature\FeatureService;
use Datto\Virtualization\EsxTester;
use Datto\Virtualization\Exceptions\RemoteStorageException;
use Datto\Virtualization\HypervisorType;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to test ESX connection
 */
class RunEsxCheckCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'virtualization:esx:test';
    private ConnectionService $connection;
    private Hardware $hardware;
    private DeviceConfig $deviceConfig;
    private Filesystem $filesystem;

    public function __construct(
        ConnectionService $connection,
        Hardware          $hardware,
        DeviceConfig      $deviceConfig,
        Filesystem        $filesystem
    ) {
        parent::__construct();
        $this->connection = $connection;
        $this->hardware = $hardware;
        $this->deviceConfig = $deviceConfig;
        $this->filesystem = $filesystem;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_HYPERVISOR_CONNECTIONS];
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Test Esx Connections.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->deviceConfig->has('inhibitAllCron')) {
            return 0;
        }

        $isVSirisOnEsx = $this->deviceConfig->has('isVirtual') && $this->hardware->detectHypervisor() === HypervisorType::VMWARE() && !$this->deviceConfig->isAltoXL();

        if (!$isVSirisOnEsx) {
            $this->filesystem->unlinkIfExists("/datto/config/hypervisor.error");
            return 0;
        }

        $brokenConnections = false;

        foreach ($this->connection->getAll() as $conn) {
            if ($conn instanceof EsxConnection) {
                if ($conn->isValid()) {
                    $tester = new EsxTester($conn);
                    try {
                        $output->writeln("Testing connection: " . $conn->getName());
                        if ($tester->testConnection() && $tester->testIScsiOffload()) {
                            $this->logger->debug('TET0011 ESX Connection Test Success');
                        } else {
                            $this->logger->error('TET0005 ESX Connection Test Failed');
                            $brokenConnections = true;
                        }
                    } catch (RemoteStorageException $ex) {
                        $this->logger->error('TET0002 Remote storage Exception', ['exception' => $ex]);
                        $brokenConnections = true;
                    } catch (Exception $ex) {
                        $this->logger->error('TET0001 Unknown error occurred', ['exception' => $ex]);
                        $brokenConnections = true;
                    }
                } else {
                    $this->logger->error('TET0004 Invalid ESX Connection');
                    $brokenConnections = true;
                }
            }
        }

        if (!$brokenConnections) {
            $this->filesystem->unlinkIfExists("/datto/config/hypervisor.error");
            return 0;
        } else {
            $this->filesystem->filePutContents('/datto/config/hypervisor.error', 'An error was detected while checking one or more hypervisor connections.');
            $this->logger->error('TET0003 An error was detected while checking one or more hypervisor connections.');
            return 1;
        }
    }
}
