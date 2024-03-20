<?php

namespace Datto\App\Console\Command\Device\Email;

use Datto\Util\Email\EmailService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class SetEmailCommand extends Command
{
    protected static $defaultName = 'device:email:set';

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
            ->setDescription('The Device Alerts Email Address will receive alert emails related to the overall health of the device such as agents being added or removed, SMART drive alerts, and others.')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'The address to use.'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getArgument('email');
        $this->emailService->setDeviceAlertsEmail($address);
        return 0;
    }
}
