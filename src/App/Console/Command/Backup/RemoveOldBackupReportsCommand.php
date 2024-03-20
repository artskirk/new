<?php

namespace Datto\App\Console\Command\Backup;

use Datto\App\Console\Command\AbstractCommand;
use Datto\Feature\FeatureService;
use Datto\Reporting\Backup\BackupReportManager;
use Datto\Resource\DateTimeService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveOldBackupReportsCommand extends AbstractCommand
{
    protected static $defaultName = 'backup:removeOldBackupReports';

    private BackupReportManager $backupReportManager;

    public function __construct(
        BackupReportManager $backupReportManager
    ) {
        parent::__construct();
        $this->backupReportManager = $backupReportManager;
    }

    protected function configure()
    {
        $this->setDescription('Removes backup reports which are older than a year from all assets.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 366 days in seconds (has to be something greater than a year, since clients can view reports that far back)
        $retentionTime = DateTimeService::SECONDS_PER_DAY * 366;

        $this->logger->info("ROB0000 Removing old backup reports");

        $this->backupReportManager->removeOldBackupReportsFromAllAgents($retentionTime);

        return 0;
    }

    public static function getRequiredFeatures(): array
    {
        return [FeatureService::FEATURE_BACKUP_REPORTS];
    }
}
