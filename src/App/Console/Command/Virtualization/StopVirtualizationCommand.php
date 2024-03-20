<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to stop a Virtualization
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class StopVirtualizationCommand extends AbstractVirtualizationCommand
{
    protected static $defaultName = 'virtualization:stop';

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
            ->setDescription('Stop a Virtualization')
            ->addArgument('agentName', InputArgument::REQUIRED, 'Agent virtualization to stop')
            ->addOption('skipRestoreUpdate', '-s', InputOption::VALUE_NONE, 'Skip updating the powered on state in the UIRestores file');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agentName');

        $isRescueAgent = $this->agentService->get($agentName)->isRescueAgent();
        $skipRestoreUpdate = $input->getOption('skipRestoreUpdate');
        if ($isRescueAgent) {
            $this->rescueAgentService->stop($agentName, null, $skipRestoreUpdate);
        } else {
            $this->virtualizationRestoreService->stopVm($agentName, $skipRestoreUpdate);
        }
        return 0;
    }
}
