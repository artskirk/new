<?php

namespace Datto\App\Console\Command\Share\User;

use Datto\Asset\Share\Nas\NasShare;
use Datto\App\Console\Command\AbstractShareCommand;
use Datto\App\Console\Command\ArgumentValidator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareUserListCommand extends AbstractShareCommand implements ArgumentValidator
{
    protected static $defaultName = 'share:user:list';

    protected function configure()
    {
        $this
            ->setDescription('List users with access to the given share (NAS share only)')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Name of the share.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $share = $this->shareService->get($input->getOption('share'));

        if ($share instanceof NasShare) {
            /** @var NasShare $share */

            $table = new Table($output);
            $table->setHeaders(array(
                'Username'
            ));

            foreach ($share->getUsers()->getAll() as $user) {
                $table->addRow(array(
                    $user
                ));
            }

            $table->render();
        }
        return 0;
    }

    public function validateArgs(InputInterface $input)
    {
        $this->commandValidator->validateValue($input->getOption('share'), new Assert\Regex(array('pattern' => "~^[[:alnum:]]+$~")), 'Name must be alphanumeric');
    }
}
