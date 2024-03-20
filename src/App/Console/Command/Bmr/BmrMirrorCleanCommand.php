<?php

namespace Datto\App\Console\Command\Bmr;

use Datto\BMR\BmrCleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to cleanup iscsi discoverydb
 *
 * @author Justin Giacobbi <jgiacobbi@datto.com>
 */
class BmrMirrorCleanCommand extends Command
{
    protected static $defaultName = 'bmr:mirror:clean';

    /** @var BmrCleaner */
    protected $cleaner;

    public function __construct(
        BmrCleaner $cleaner
    ) {
        parent::__construct();

        $this->cleaner = $cleaner;
    }

    protected function configure()
    {
        $this
            ->setDescription('Cleans up stale iscsi discoverydb entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cleaner->cleanDiscoveryDB();
        return 0;
    }
}
