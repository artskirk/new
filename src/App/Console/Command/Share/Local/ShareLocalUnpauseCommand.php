<?php

namespace Datto\App\Console\Command\Share\Local;

use Datto\Asset\Share\Share;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Datto\App\Console\Command\AbstractShareCommand;

class ShareLocalUnpauseCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:local:unpause';


    protected function configure()
    {
        $this
            ->setDescription('Resume all snapshots for the given share(s).')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Set snapshot unpause for a share')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set snapshot unpause for all current shares');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);
        $shares = $this->getShares($input);

        /** @var Share $share */
        foreach ($shares as $share) {
            $share->getLocal()->setPaused(false);
            $this->shareService->save($share);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
    }
}
