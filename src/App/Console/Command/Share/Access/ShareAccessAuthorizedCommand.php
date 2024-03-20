<?php

namespace Datto\App\Console\Command\Share\Access;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAccessAuthorizedCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:access:authorized';

    protected function configure()
    {
        $this
            ->setDescription('Set authorized user that will have access to Samba share during file restore.')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share to set authorized user for.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set authorized user for all shares.')
            ->addArgument('authorizedUser', InputArgument::OPTIONAL, 'Authorized user username');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $authorizedUser = $input->getArgument('authorizedUser');
        $authorizedUserString = ($authorizedUser) ?: '';

        foreach ($shares as $share) {
            /** @var NasShare $share */
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $share->getAccess()->setAuthorizedUser($authorizedUserString);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);
        $this->commandValidator->validateValue(
            $input->getArgument('authorizedUser'),
            new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")),
            'Username must be alphanumeric'
        );
    }
}
