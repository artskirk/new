<?php
namespace Datto\App\Console\Command\Share\Alert;

use Datto\App\Console\Command\AbstractShareCommand;
use Datto\Asset\Share\Share;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ShareAlertWarningCommand extends AbstractShareCommand
{
    protected static $defaultName = 'share:alert:warning';

    protected function configure()
    {
        $this
            ->setDescription('Set the email list for Warning Alert messages')
            ->addOption('share', 's', InputOption::VALUE_REQUIRED, 'Set warning alert email list for a share')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Set warning alert email list for all current shares')
            ->addArgument('emails', InputArgument::IS_ARRAY, 'Mailing list for warning alert messages, separated by spaces');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateArgs($input);

        $shares = $this->getShares($input);
        $emails = $input->getArgument('emails');

        /** @var Share $share */
        foreach ($shares as $share) {
            $share->getEmailAddresses()->setWarning($emails);
            $this->shareService->save($share);
        }
        return 0;
    }

    protected function validateArgs(InputInterface $input): void
    {
        $this->validateShare($input);

        $emails = $input->getArgument('emails');
        foreach ($emails as $email) {
            $this->commandValidator->validateValue(
                $email,
                new Assert\Regex("/^[^,@]+@[^,]+$/"),
                'Each email address must have at least one @ sign. Email addresses may not contian commas.'
            );
        }
    }
}
