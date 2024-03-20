<?php

namespace Datto\App\Console\Command\Iscsi;

use Datto\Iscsi\IscsiTarget;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This command lists all block backing stores and their associated paths
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class IscsiBackstoreListCommand extends Command
{
    protected static $defaultName = 'iscsi:backstore:list';

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
            ->setDescription('Lists iSCSI backstores');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $map = $this->iscsiTarget->getBlockBackingStoreMap();

        $table = new Table($output);
        $table->setHeaders(['Backstore Name', 'Path']);
        foreach ($map as $path => $blockName) {
            $table->addRow([$blockName, $path]);
        }
        $table->render();
        return 0;
    }
}
