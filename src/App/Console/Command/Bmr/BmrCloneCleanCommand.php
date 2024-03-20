<?php

namespace Datto\App\Console\Command\Bmr;

use Datto\BMR\BmrCleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BmrCloneCleanCommand extends Command
{
    protected static $defaultName = 'bmr:clone:clean';

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
            ->setDescription('Attempts to clean up mas clone loops')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force immediate cleanup');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force') !== false;
        $this->cleaner->cleanStaleBmrs($force);
        return 0;
    }
}
