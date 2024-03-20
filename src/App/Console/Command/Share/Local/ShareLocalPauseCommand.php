<?php

namespace Datto\App\Console\Command\Share\Local;

use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShareLocalPauseCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:local:pause';

    protected function configure()
    {
        $this
            ->setDescription('Pause all snapshots for the given share(s).')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Set snapshot pause for a share')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set snapshot pause for all current shares')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $shares = $this->getShares($input);

        /** @var Share $share */
        foreach ($shares as $share) {
            $share->getLocal()->setPaused(true);
            $this->shareService->save($share);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
    }
}
