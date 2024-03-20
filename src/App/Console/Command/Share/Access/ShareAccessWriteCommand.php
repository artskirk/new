<?php

namespace Datto\App\Console\Command\Share\Access;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAccessWriteCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:access:write';

    protected function configure()
    {
        $this
            ->setDescription('Enable/disable write access for the creator or all users.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set write access for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set write access for all shares.')
            ->addArgument('writeAccessLevel', InputArgument::REQUIRED, 'Access level to be given [creator|all]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $writeAccessLevel = $input->getArgument('writeAccessLevel');

        foreach ($shares as $share) {
            /** @var NasShare $share */
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $share->getAccess()->setWriteLevel($writeAccessLevel);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
        $this->commandValidator->validateValue(
            $input->getArgument('writeAccessLevel'),
            new Assert\Choice(array('creator', 'all'))
        );
    }
}
