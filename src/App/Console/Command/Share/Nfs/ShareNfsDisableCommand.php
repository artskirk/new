<?php

namespace Datto\App\Console\Command\Share\Nfs;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareNfsDisableCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:nfs:disable';

    protected function configure()
    {
        $this
            ->setDescription('Disable NFS for share(s)')
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
                $share->getNfs()->disable();
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
