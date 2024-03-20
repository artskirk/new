<?php

namespace Datto\App\Console\Command\Share\Access;

use Datto\Asset\Share\Nas\AccessSettings;
use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAccessWriteFixCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:access:write:fix';

    protected function configure()
    {
        $this
            ->setDescription('Fix write all permissions for a share (essentially chmod 777!).')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set write access for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set write access for all shares.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateShare($input);
        $shares = $this->getShares($input);

        foreach ($shares as $share) {
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $isWriteAll = $share->getAccess()->getWriteLevel() == AccessSettings::WRITE_ACCESS_LEVEL_ALL;

                if ($isWriteAll) {
                    // This may seem redundant, but it re-applies write-all
                    // permissions (this essentially runs chmod 0777)!

                    $share->getAccess()->setWriteLevel(AccessSettings::WRITE_ACCESS_LEVEL_ALL);
                }
            }
        }

        return 0;
    }
}
