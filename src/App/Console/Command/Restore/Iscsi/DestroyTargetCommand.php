<?php

namespace Datto\App\Console\Command\Restore\Iscsi;

use Datto\Restore\Iscsi\IscsiMounterService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @author Bartosz Neuman <bneuman@datto.com>
 */
class DestroyTargetCommand extends AbstractIscsiCommand
{
    protected static $defaultName = 'restore:iscsi:destroy';

    protected function configure()
    {
        $this
            ->setDescription('Destroys target created by iSCSI mounter.')
            ->addOption('agent', 's', InputOption::VALUE_REQUIRED, "Specify an agent to destroy the iSCSI target.")
            ->addOption('snapshot', 'S', InputOption::VALUE_REQUIRED, "Specify a snapshot of agent to destroy the iSCSI target.");
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validator->validateValue($input->getOption('agent'), new Assert\NotNull(), 'Agent must be specified');
        $this->validator->validateValue($input->getOption('snapshot'), new Assert\NotNull(), 'Snapshot must be specified');
        $this->validator->validateValue($input->getOption('snapshot'), new Assert\Regex(array('pattern' => "~^[[:graph:]]+$~")), 'Snapshot must be alphanumeric');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $agent = $input->getOption('agent');
        $snapshot = $input->getOption('snapshot');

        $this->iscsiMounter->destroyIscsiTarget($agent, $snapshot);
        $this->iscsiMounter->destroyClone($agent, $snapshot);
        // TODO: IscsiMounterService::[add|remove]Restore were hard-coding suffix to SUFFIX_RESTORE,
        //       whereas createIscsiTarget call above was using SUFFIX_EXPORT in target name, therefore,
        //       we have to mimic this bug to not break ability to tear down any exising iscsi restores
        //       created by this CLI command :-/
        $this->iscsiMounter->setSuffix(IscsiMounterService::SUFFIX_RESTORE);
        $this->iscsiMounter->removeRestore($agent, $snapshot);
        return 0;
    }
}
