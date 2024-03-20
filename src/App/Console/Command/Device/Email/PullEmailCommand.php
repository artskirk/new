<?php

namespace Datto\App\Console\Command\Device\Email;

use Datto\Util\Email\EmailService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class PullEmailCommand extends Command
{
    protected static $defaultName = 'device:email:pull';

    /** @var EmailService */
    private $emailService;

    public function __construct(
        EmailService $emailService
    ) {
        parent::__construct();

        $this->emailService = $emailService;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Pulls the device alerts email from the cloud onto the device.')
            ->addOption('overwrite', 'o', InputOption::VALUE_NONE, 'Overwrite the devices value with what is in the cloud.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $overwrite = $input->getOption('overwrite');
        $output->writeln($this->emailService->pullDevicePrimaryContactEmailFromCloud($overwrite));
        return 0;
    }
}
