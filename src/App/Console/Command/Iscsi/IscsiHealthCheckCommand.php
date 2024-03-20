<?php

namespace Datto\App\Console\Command\Iscsi;

use Datto\Service\Status\IscsiHealthCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Check for stuck targetcli calls that could be problematic for iscsi connections
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class IscsiHealthCheckCommand extends Command
{
    protected static $defaultName = 'iscsi:health:check';

    /**
     * @var IscsiHealthCheck
     */
    private $iscsiHealthCheckService;

    public function __construct(
        IscsiHealthCheck $iscsiHealthCheckService
    ) {
        parent::__construct();

        $this->iscsiHealthCheckService = $iscsiHealthCheckService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setDescription('Check the health of the iSCSI subsystem and optionally report it to ELK');
        $this->addOption('send-event', null, InputOption::VALUE_NONE, 'Send an Event to ELK');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sendEvent = $input->getOption('send-event');
        $this->iscsiHealthCheckService->performHealthCheck($sendEvent);
        return 0;
    }
}
