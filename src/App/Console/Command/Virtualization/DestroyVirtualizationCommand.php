<?php

namespace Datto\App\Console\Command\Virtualization;

use Datto\App\Console\Input\InputArgument;
use Datto\Feature\FeatureService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Snapctl command to destroy a virtualization
 *
 * @author Michael Corbeil <mcorbeil@datto.com>
 */
class DestroyVirtualizationCommand extends AbstractVirtualizationCommand
{
    protected static $defaultName = 'virtualization:destroy';

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
            ->setDescription('Destroy a Virtualization or all virtualizations')
            ->addArgument('agentName', InputArgument::OPTIONAL, 'Agent VM to destroy')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Recovery point for Agent VM to destroy')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Remove all virtualizations.  Must not have any arguments.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentName = $input->getArgument('agentName');
        $snapshot = $input->getArgument('snapshot');
        $isAll = $input->getOption('all');
        if ($isAll) {
            $this->checkArgsForDestroyAll($agentName, $snapshot);
            // TODO: remove following restores for following suffixes: iscsi, file, export, esx-upload, iscsimounter, rescue, hypbrid-virt
            $this->virtualizationRestoreService->destroyAllActiveRestores();
        } else {
            $this->checkArgsForDestroyOne($agentName, $snapshot);
            $this->virtualizationRestoreService->destroyVm($agentName, $snapshot);
        }
        return 0;
    }

    /**
     * Checks for argument consistency for destroying one virtualization.
     *
     * @param string|null $agentName
     * @param string|null $snapshot epoch
     */
    private function checkArgsForDestroyOne($agentName, $snapshot): void
    {
        $isAgentAndSnapshot = $agentName !== null && $snapshot !== null;
        if (!$isAgentAndSnapshot) {
            throw new \Exception('If not destroying all virtualizations, agent and snapshot must be supplied.');
        }
    }

    /**
     * Checks for argument consistency for destroying one virtualization.
     *
     * @param string|null $agentName
     * @param string|null $snapshot epoch
     */
    private function checkArgsForDestroyAll($agentName, $snapshot): void
    {
        $isAgentOrSnapshot = $agentName !== null || $snapshot !== null;
        if ($isAgentOrSnapshot) {
            throw new \Exception('If destroying all virtualizations, neither agent nor snapshot may be supplied.');
        }
    }
}
