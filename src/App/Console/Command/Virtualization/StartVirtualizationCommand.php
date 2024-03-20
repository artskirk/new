<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to start a Virtualization
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class StartVirtualizationCommand extends AbstractVirtualizationCommand
{
    protected static $defaultName = 'virtualization:start';

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_RESTORE_VIRTUALIZATION];
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Start a Virtualization')
            ->addArgument('agentName', InputArgument::REQUIRED, 'Agent to start VM for')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Recovery point to start VM for, defaults to latest if not provided')
            ->addOption('connectionName', 'c', InputOption::VALUE_REQUIRED, 'Name of Hypervisor connection');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agentName');
        $agent = $this->agentService->get($agentName);
        $snapshot = (int)($input->getArgument('snapshot') ?? $this->getLatestSnapshot($agentName));
        $connectionName = $input->getOption('connectionName') ?? $this->getDefaultConnectionName();
        $passphrase = $this->promptAgentPassphraseIfRequired($agent, $this->tempAccessService, $input, $output);
        $start = true;

        $isRescueAgent = $this->agentService->get($agentName)->isRescueAgent();
        if ($isRescueAgent) {
            $this->rescueAgentService->start($agentName, $passphrase);
        } else {
            $this->virtualizationRestoreService->startVm($agentName, $snapshot, $connectionName, $passphrase, $start);
        }
        return 0;
    }
}
