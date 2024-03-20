<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to create a VM.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class CreateVirtualizationCommand extends AbstractVirtualizationCommand
{
    protected static $defaultName = 'virtualization:create';

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
            ->setDescription('Create a Virtualization')
            ->addArgument('agentName', InputArgument::REQUIRED, 'Agent to create VM for.')
            ->addArgument(
                'snapshot',
                InputArgument::OPTIONAL,
                'Recovery point to create VM for, defaults to latest if not ' .
                'provided'
            )
            ->addOption('connectionName', 'c', InputOption::VALUE_REQUIRED, 'Name of Hypervisor connection')
            ->addOption(
                'native',
                null,
                InputOption::VALUE_NONE,
                'Use the configuration captured from the native host during ' .
                'backup, if available, rather than virtualization settings ' .
                'provided by the device'
            );
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
        $hasNativeConfiguration = (bool) $input->getOption('native');
        $passphrase = $this->promptAgentPassphraseIfRequired($agent, $this->tempAccessService, $input, $output);
        $start = false;

        $this->virtualizationRestoreService->startVm(
            $agentName,
            $snapshot,
            $connectionName,
            $passphrase,
            $hasNativeConfiguration,
            $start
        );

        return 0;
    }
}
