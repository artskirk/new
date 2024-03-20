<?php

namespace Datto\App\Console\Command\Backup;

use Datto\Backup\ConfigBackupService;
use Datto\Log\LoggerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ConfigBackupCommand
 *
 * This class implements a command to copy config files from /datto/config/ and
 * /etc/ to /home/configBackup/. This command also moves rotated log files from
 * /var/log/, /datto/config/keys/, etc. to /home/configBackup/.
 *
 * @author Pankaj Gupta <pgupta@datto.com>
 */
class ConfigBackupCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'backup:config';

    /** @var ConfigBackupService */
    private $configBackup;

    public function __construct(
        ConfigBackupService $configBackup
    ) {
        parent::__construct();

        $this->configBackup = $configBackup;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $description = 'Copies configs from /datto/config/ and /etc/ and moves ' .
            'logs from /var/log/ and /datto/config/keys/ to /home/configBackup/.';
        $this
            ->setDescription($description)
            ->addOption('partial', null, InputOption::VALUE_NONE, 'Perform a partial backup.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->setAssetContext('configBackup');
        $this->logger->info("CNF2027 Received command to backup configs and logs.");

        if ($input->getOption('partial')) {
            // Backup configs
            $this->configBackup->partialBackup();
        } else {
            // Backup configs and logs and send them offsite
            $this->configBackup->backup();
        }
        return 0;
    }
}
