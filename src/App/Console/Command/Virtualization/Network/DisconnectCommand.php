<?php

namespace Datto\App\Console\Command\Virtualization\Network;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Virtualization\CloudNetworking\CloudVirtualizationNetworkingService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * DisconnectCommand removes the connection to a cloud network using datto-netctl
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class DisconnectCommand extends AbstractCommand
{
    protected static $defaultName = 'virtualization:network:disconnect';

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
            ->setDescription('Remove the connection to a parent network via datto-netctl')
            ->addArgument('networkUuid', InputArgument::REQUIRED, 'The UUID of the parent network to disconnect from the device');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $networkUuid = $input->getArgument('networkUuid');

        $this->cloudVirtualizationNetworkingService->disconnect($networkUuid);

        return 0;
    }
}
