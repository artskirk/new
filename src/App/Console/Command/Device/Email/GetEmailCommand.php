<?php

namespace Datto\App\Console\Command\Device\Email;

use Datto\Config\ContactInfoRecord;
use Datto\Config\DeviceConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class GetEmailCommand extends Command
{
    protected static $defaultName = 'device:email:get';

    /** @var DeviceConfig */
    private $deviceConfig;

    public function __construct(
        DeviceConfig $deviceConfig
    ) {
        parent::__construct();

        $this->deviceConfig = $deviceConfig;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Gets the device alerts email.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $contactInfoRecord = new ContactInfoRecord();
        $this->deviceConfig->loadRecord($contactInfoRecord);
        $output->writeln($contactInfoRecord->getEmail());
        return 0;
    }
}
