<?php

namespace Datto\App\Console\Command\Alerts;

use Datto\Alert\BackupAlertService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to check for outdated backups and trigger alerts.
 *
 * @author Christopher R. Wicks <cwicks@datto.com
 */
class OutdatedBackupAlertCommand extends Command
{
    protected static $defaultName = 'alerts:backup:outdated';

    /** @var BackupAlertService */
    private $backupAlertService;

    public function __construct(
        BackupAlertService $backupAlertService
    ) {
        parent::__construct();

        $this->backupAlertService = $backupAlertService;
    }

    /**
     * @inheritdoc
     */
    public function configure()
    {
        $this
            ->setDescription("Checks all assets for outdated backups and triggers alerts if found");
    }

    /**
     * @inheritdoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->backupAlertService->checkForOutdatedBackups();
        return 0;
    }
}
