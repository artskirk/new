<?php

namespace Datto\App\Console\Command\Email;

use Datto\Util\Email\Generator\MimeEmailGenerator;
use Datto\Common\Utility\Filesystem;
use Datto\Util\Email\EmailService;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command for sending email.
 *
 * @author John Roland <jroland@datto.com>
 */
class EmailCommand extends Command
{
    protected static $defaultName = 'email';

    /** @var Filesystem */
    private $filesystem;

    /** @var EmailService */
    private $emailService;

    /** @var MimeEmailGenerator */
    private $mimeEmailGenerator;

    public function __construct(
        Filesystem $filesystem,
        EmailService $emailService,
        MimeEmailGenerator $mimeEmailGenerator
    ) {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->emailService = $emailService;
        $this->mimeEmailGenerator = $mimeEmailGenerator;
    }

    /**
     * Configure the console command.
     */
    protected function configure()
    {
        $this
            ->setDescription('Sends an email using the Siris Email Service. '
                . 'This command currently only handles input via stdin (no arguments).'
                . 'The input should be in basic MIME format. However, it does not handle attachements. '
                . 'The input MUST have the recipients, subject, and message. '
                . 'Headers will be ignored but can be in the input.');
    }

    /**
     * Parse the input and send the email.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stdin = $this->filesystem->fileGetContents('php://stdin');

        $email = $this->mimeEmailGenerator->generate($stdin);
        $sent = $this->emailService->sendEmail($email);

        if (!$sent) {
            throw new Exception('Failed to send email.');
        }
        return 0;
    }
}
