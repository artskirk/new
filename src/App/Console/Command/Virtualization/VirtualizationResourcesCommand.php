<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Datto\Restore\Virtualization\ChangeResourcesRequest;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class VirtualizationResourcesCommand extends AbstractVirtualizationCommand
{
    protected static $defaultName = 'virtualization:resources';

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION];
    }

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->setDescription('Get or set resources for virtualizations')
            ->addArgument('agentName', InputArgument::REQUIRED, 'Agent to change resources for')
            ->addOption('connectionName', 'c', InputOption::VALUE_REQUIRED, 'Name of Hypervisor connection')
            ->addOption('set-cpu-cores', null, InputOption::VALUE_REQUIRED, 'Number of CPU cores')
            ->addOption('set-ram', null, InputOption::VALUE_REQUIRED, 'Amount of RAM in MiB')
            ->addOption('set-storage-controller', null, InputOption::VALUE_REQUIRED, 'Storage controller')
            ->addOption('set-video-controller', null, InputOption::VALUE_REQUIRED, 'Video controller')
            ->addOption('set-network-controller', null, InputOption::VALUE_REQUIRED, 'Network controller')
            ->addOption('set-network-mode', null, InputOption::VALUE_REQUIRED, 'Network mode (bridged, none, etc)');
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agentName');
        $connectionName = $input->getOption('connectionName') ?? $this->getDefaultConnectionName();

        if ($this->hasResourceChanges($input)) {
            $request = $this->getRequestFromInput($input);
            $this->virtualizationRestoreService->changeResources($agentName, $connectionName, $request);
        } else {
            $settings = $this->virtualizationRestoreService->getResources($agentName, $connectionName);

            $table = new Table($output);
            $table->setHeaders([
                'Setting',
                'Value'
            ]);
            $table->addRows([
                ['Custom Settings', $settings->isUserDefined() ? 'yes' : 'no'],
                ['CPU Cores', $settings->getCpuCount()],
                ['RAM (MiB)', $settings->getRam()],
                ['Storage Controller', $settings->getStorageController()],
                ['Video Controller', $settings->getVideoController()],
                ['Network Controller', $settings->getNetworkController()],
                ['Network Mode', $settings->getNetworkModeRaw()]
            ]);
            $table->render();
        }
        return 0;
    }

    /**
     * @param InputInterface $input
     * @return ChangeResourcesRequest
     */
    private function getRequestFromInput(InputInterface $input): ChangeResourcesRequest
    {
        $request = new ChangeResourcesRequest();

        if ($input->getOption('set-cpu-cores') !== null) {
            $request->setCpuCount($input->getOption('set-cpu-cores'));
        }
        if ($input->getOption('set-ram') !== null) {
            $request->setMemoryInMB($input->getOption('set-ram'));
        }
        if ($input->getOption('set-storage-controller') !== null) {
            $request->setStorageController($input->getOption('set-storage-controller'));
        }
        if ($input->getOption('set-video-controller') !== null) {
            $request->setVideoController($input->getOption('set-video-controller'));
        }
        if ($input->getOption('set-network-controller') !== null) {
            $request->setNetworkController($input->getOption('set-network-controller'));
        }
        if ($input->getOption('set-network-mode') !== null) {
            $request->setNetworkMode($input->getOption('set-network-mode'));
        }

        return $request;
    }

    /**
     * @param InputInterface $input
     * @return bool
     */
    private function hasResourceChanges(InputInterface $input): bool
    {
        $hasChanges = $input->getOption('set-cpu-cores') !== null
            || $input->getOption('set-ram') !== null
            || $input->getOption('set-storage-controller') !== null
            || $input->getOption('set-video-controller') !== null
            || $input->getOption('set-network-controller') !== null
            || $input->getOption('set-network-mode') !== null;

        return $hasChanges;
    }
}
