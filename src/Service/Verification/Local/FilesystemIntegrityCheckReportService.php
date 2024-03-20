<?php

namespace Datto\Service\Verification\Local;

use Datto\Asset\Agent\AgentRepository;
use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Service to persist and retrieve file integrity reports.
 * These files are never read again once written and could be used by support for examining failures.
 *
 * @author Mark Blakley <mblakley@datto.com>
 */
class FilesystemIntegrityCheckReportService
{
    /** The suffix for filesystem integrity check report filenames */
    private const FILESYSTEM_INTEGRITY_REPORT_EXTENSION = '.filesystemIntegrityReport';

    private const MKDIR_MODE = 0777;

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Store a report on the disk in the correct location, based on assetKey and snapshotEpoch.
     */
    public function save(string $assetKey, int $snapshotEpoch, string $report)
    {
        $this->filesystem->mkdirIfNotExists(AgentRepository::BASE_CONFIG_PATH . "/$assetKey.reports", false, self::MKDIR_MODE);
        $fileIntegrityCheckReportFile = $this->getAssetSnapshotReportFilePath($assetKey, $snapshotEpoch);
        $result = $this->filesystem->filePutContents($fileIntegrityCheckReportFile, $report);
        if ($result === false) {
            throw new Exception("Error writing filesystem integrity report for asset $assetKey, snapshot $snapshotEpoch");
        }
    }

    /**
     * Deletes the report file with the given assetKey and snapshotEpoch
     */
    public function destroy(string $assetKey, int $snapshotEpoch)
    {
        $fileIntegrityCheckReportFile = $this->getAssetSnapshotReportFilePath($assetKey, $snapshotEpoch);
        if ($this->filesystem->exists($fileIntegrityCheckReportFile)) {
            $this->filesystem->unlink($fileIntegrityCheckReportFile);
            $assetReportDir = $this->getAssetReportsDirPath($assetKey);
            if ($this->filesystem->isEmptyDir($assetReportDir)) {
                $this->filesystem->rmdir($assetReportDir);
            }
        }
    }

    /**
     * Deletes all of the report files for the given assetKey
     */
    public function destroyAssetReports(string $assetKey)
    {
        $assetReportDir = $this->getAssetReportsDirPath($assetKey);
        if ($this->filesystem->exists($assetReportDir)) {
            $reportFiles = $this->filesystem->glob("$assetReportDir/*" . self::FILESYSTEM_INTEGRITY_REPORT_EXTENSION);
            foreach ($reportFiles as $reportFile) {
                $this->filesystem->unlink($reportFile);
            }
            if ($this->filesystem->isEmptyDir($assetReportDir)) {
                $this->filesystem->rmdir($assetReportDir);
            }
        }
    }

    /**
     * Gets the path of the reports directory for the given asset.  Does not include the "/" on the end.
     */
    private function getAssetReportsDirPath(string $assetKey): string
    {
        return AgentRepository::BASE_CONFIG_PATH . "/$assetKey.reports";
    }

    /**
     * Gets the path of the report file, based on asset key and snapshot epoch
     */
    private function getAssetSnapshotReportFilePath(string $assetKey, int $snapshotEpoch): string
    {
        return $this->getAssetReportsDirPath($assetKey) . "/$snapshotEpoch" . static::FILESYSTEM_INTEGRITY_REPORT_EXTENSION;
    }
}
