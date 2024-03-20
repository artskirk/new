<?php

namespace Datto\App\Console\Command\Iscsi;

use Datto\App\Console\Input\InputArgument;
use Datto\Iscsi\IscsiTarget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command deletes an iSCSI target, its backing store, and loop
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiTargetDeleteCommand extends Command
{
    protected static $defaultName = 'iscsi:target:delete';

    /** @var IscsiTarget */
    private $iscsiTarget;

    public function __construct(
        IscsiTarget $iscsiTarget
    ) {
        parent::__construct();

        $this->iscsiTarget = $iscsiTarget;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Deletes an iSCSI target')
            ->addArgument('target', InputArgument::REQUIRED, 'iSCSI target');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');

        $this->iscsiTarget->deleteTarget($target);
        return 0;
    }
}
