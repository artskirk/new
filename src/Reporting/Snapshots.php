<?php

namespace Datto\Reporting;

use Datto\Common\Resource\Zlib;
use Datto\Common\Utility\Filesystem;
use Datto\Config\AgentConfigFactory;
use Datto\Reporting\Backup\BackupReportManager;
use Datto\Resource\DateTimeService;

class Snapshots extends Reporting
{
    private BackupReportManager $backupReportManager;

    public function __construct(
        Filesystem $filesystem = null,
        Zlib $zlib = null,
        BackupReportManager $backupReportManager = null
    ) {
        parent::__construct($filesystem, $zlib);
        $this->fileSuffix = ".snp.log";
        $this->logTag = "snp";
        $this->codeGroups = array(
            'SNP613' => array("613", "SNP0613", "BKP0613"),
            'SNP100' => array("100", "SNP0100", "BKP0100"),
            'SNP125' => array("125", "SNP0125", "126", "SNP0126", "BKP0126", "BKP0125", "BKP2126", "BAK0125"),
            'SNP300' => array("300", "SNP0300", "BKP0300", "BKP3300")
        );
        $this->backupReportManager = $backupReportManager ?? new BackupReportManager(
            $this->filesystem,
            new DateTimeService(),
            new AgentConfigFactory()
        );
    }

    /**
     * Reads information about backups from the backupReports file.
     * Turns it into an array that can be accepted by the UI.
     *
     * @param string $hostname Domain of the agent
     * @return array Array of logs
     */
    public function getLogs($hostname): array
    {
        $entries = [];
        $backupArray = $this->backupReportManager->readReports($hostname);

        foreach ($backupArray as $backup) {
            $entry = array(
                'type' => $backup->getType(),
                'success' => $backup->getSuccess(),
                'start_time' => $backup->getScheduledTime(),
            );
            $entries[] = $entry;
        }

        return $entries;
    }

    protected function generateOrganizedEntry($entry)
    {
        // not used
    }
}
