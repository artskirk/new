<?php

namespace Datto\App\Console\Command\Share\User;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\ArgumentValidator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareUserAddCommand extends AbstractShareCommand implements ArgumentValidator
{
    protected static $defaultName = 'share:user:add';

    protected function configure()
    {
        $this
            ->setDescription('Add access for a user to one or many shares (NAS share only)')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share.')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Apply to all.')
            ->addArgument('username', InputArgument::REQUIRED, 'Name of the user.')
            ->addArgument('privilege', InputArgument::OPTIONAL, 'Set privileges [read-only|admin]', 'read-only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $user = $input->getArgument('username');
        $isAdmin = $input->getArgument('privilege') === 'admin';

        foreach ($shares as $share) {
            /** @var NasShare $share */
            if ($share instanceof NasShare && !$share->getOriginDevice()->isReplicated()) {
                $share->getUsers()->add($user, $isAdmin);
                $this->shareService->save($share);
            }
        }
        return 0;
    }

    public function validateArgs(InputInterface $input)
    {
        $this->validateShare($input);

        $this->commandValidator->validateValue($input->getArgument('username'), new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")), 'Username must be alphanumeric');
        $this->commandValidator->validateValue($input->getArgument('privilege'), new Assert\Choice(array('choices' => array("read-only", "admin"))));
    }
}
