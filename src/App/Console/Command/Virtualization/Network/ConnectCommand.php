<?php

namespace Datto\App\Console\Command\Virtualization\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Virtualization\CloudNetworking\CloudVirtualizationNetworkingService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ConnectCommand
 *
 * @author Jimi Ford <jford@datto.com>
 */
class ConnectCommand extends AbstractCommand
{
    protected static $defaultName = 'virtualization:network:connect';

    private CloudVirtualizationNetworkingService $cloudVirtualizationNetworkingService;

    public function __construct(CloudVirtualizationNetworkingService $cloudVirtualizationNetworkingService)
    {
        parent::__construct();
        $this->cloudVirtualizationNetworkingService = $cloudVirtualizationNetworkingService;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION_HYBRID];
    }

    protected function configure()
    {
        $this
            ->setDescription('Configure an external client to connect to a parent network via datto-netctl')
            ->addOption('networkUuid', null, InputOption::VALUE_REQUIRED, 'The UUID of the parent network to configure for')
            ->addOption('shortCode', null, InputOption::VALUE_REQUIRED, 'The short code of the network to restore (unique human-readable identifier of cloud network)')
            ->addOption('parentFqdn', null, InputOption::VALUE_REQUIRED, 'Fully Qualified Domain Name of the parent network host (e.g. server."masterNode".dattobackup.com)')
            ->addOption('parentPort', null, InputOption::VALUE_REQUIRED, 'OpenVPN Port on the server to which we want the client to connect')
            ->addOption('credentialsPath', null, InputOption::VALUE_REQUIRED, 'The temporary directory containing the clientKey, clientCertificate, and caCertificate. Snapctl will delete this directory after process completes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $networkUuid = $input->getOption('networkUuid');
        $shortCode = $input->getOption('shortCode');
        $parentFqdn = $input->getOption('parentFqdn');
        $parentPort = $input->getOption('parentPort');
        $credentialsPath = $input->getOption('credentialsPath');

        $this->cloudVirtualizationNetworkingService->connect(
            $networkUuid,
            $shortCode,
            $parentFqdn,
            $parentPort,
            $credentialsPath
        );

        return 0;
    }
}
