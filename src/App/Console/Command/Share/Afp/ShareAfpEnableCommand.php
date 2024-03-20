<?php

namespace Datto\App\Console\Command\Share\Afp;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAfpEnableCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:afp:enable';

    protected function configure()
    {
        $this
            ->setDescription('Enable AFP for share(s)')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Apply to all.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            /** @var NasShare $share */
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $share->getAfp()->enable();
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
    }
}
