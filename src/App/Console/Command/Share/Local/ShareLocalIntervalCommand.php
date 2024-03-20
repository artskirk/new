<?php

namespace Datto\App\Console\Command\Share\Local;

use Datto\Asset\Share\Share;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareLocalIntervalCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:local:interval';

    protected function configure()
    {
        $this
            ->setDescription('Change the snapshot interval setting for the given share(s).')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Set snapshot interval for a share')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set snapshot interval for all current shares')
            ->addArgument('interval', InputArgument::REQUIRED, 'Interval for how often snapshots will occur. [5|10|15|30|60]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $interval = $input->getArgument('interval');

        /** @var Share $share */
        foreach ($shares as $share) {
            if (!$share->getOriginDevice()->isReplicated()) {
                $share->getLocal()->setInterval($interval);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue(
            $input->getArgument('interval'),
            new Assert\Choice(array(5, 10, 15, 30, 60)),
            'Interval must be 5, 10, 15, 30 or 60'
        );
    }
}
