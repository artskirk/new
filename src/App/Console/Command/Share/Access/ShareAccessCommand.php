<?php

namespace Datto\App\Console\Command\Share\Access;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAccessCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:access';

    protected function configure()
    {
        $this
            ->setDescription('Set share access to public or private.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set access level for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set access level for all shares.')
            ->addArgument('accessLevel', InputArgument::REQUIRED, 'Access level to be given [public|private]');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $level = $input->getArgument('accessLevel');

        foreach ($shares as $share) {
            /** @var NasShare $share */
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $share->getAccess()->setLevel($level);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
        $this->commandValidator->validateValue(
            $input->getArgument('accessLevel'),
            new Assert\Choice(array('public', 'private'))
        );
    }
}
