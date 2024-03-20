<?php

namespace Datto\DirectToCloud;

use Datto\Asset\Agent\AgentService;
use Datto\Common\Resource\ProcessFactory;
use Datto\Common\Utility\Filesystem;
use Datto\Resource\DateTimeService;
use Datto\Utility\Network\Hostname;
use Exception;

class SupportZip
{
    private const ZIP_MAX_SIZE = 268435456; // Arbitrary max file size of 256M
    private const ZIP_NAME_TEMPLATE = 'support-%s-%s-%d.zip';
    private const ZIP_CONFIG_PATH = '/etc/datto/supportzip/supportzip.json';
    private \ZipArchive $zipArchive;
    private Filesystem $filesystem;
    private AgentService $agentService;
    private ProcessFactory $processFactory;
    private DateTimeService $dateTimeService;
    private Hostname $hostname;
    private int $zipMaxSize = 0;
    private string $zipPath = '';
    private bool $truncated = false;

    public function __construct(
        int $zipMaxSize = 0,
        \ZipArchive $zipArchive = null,
        Filesystem $filesystem = null,
        AgentService $agentService = null,
        ProcessFactory $processFactory = null,
        DateTimeService $dateTimeService = null,
        Hostname $hostname = null
    ) {
        $this->zipMaxSize = ($zipMaxSize > 0) ? $zipMaxSize : self::ZIP_MAX_SIZE;
        $this->zipArchive = $zipArchive ?: new \ZipArchive();
        $this->processFactory = $processFactory ?: new ProcessFactory();
        $this->filesystem = $filesystem ?: new Filesystem($this->processFactory);
        $this->agentService = $agentService ?: new AgentService();
        $this->dateTimeService = $dateTimeService ?: new DateTimeService();
        $this->hostname = $hostname ?: new Hostname($this->processFactory, $this->filesystem);
    }

    public function cleanup(): void
    {
        $this->filesystem->unlinkIfExists($this->zipPath);
    }

    public function getZipPath(): string
    {
        return $this->zipPath;
    }

    /*
     * isTruncated() returns true if the zip file was created but not all available log files could be
     * added due to file size constraints. Although not all of the log files were added, the zip file is
     * still a valid zip file.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getMd5sum(): string
    {
        return ($this->filesystem->isFile($this->zipPath)) ?
            $this->filesystem->hashFile('md5', $this->zipPath) : '';
    }

    public function getBase64(): string
    {
        $fileContent = $this->filesystem->fileGetContents($this->zipPath);
        if ($fileContent === false) {
            return '';
        }
        return base64_encode($fileContent);
    }

    /*
     * Build a zip file with contents defined by /etc/datto/supportzip/supportzip.json
     * @return array - contains the list of files added to the zip.
     */
    public function build(
        string $basePath,
        string $agentKeyName = '',
        bool $includeRotatedLogs = false,
        int $rotatedLogsDaysBack = 0
    ): array {
        $configArray = $this->readConfig();
        $fileList = [];
        $this->zipPath = $basePath . '/' . $this->buildFilename($agentKeyName);
        $this->filesystem->unlinkIfExists($this->zipPath);
        if (empty($configArray) || !array_key_exists('logFilePaths', $configArray)) {
            throw new Exception('Malformed configArray');
        }
        $hasAgentInfo = $this->hasAgentInfo($agentKeyName);
        $this->truncated = false;
        foreach ($configArray['logFilePaths'] as $configKey => $configBlock) {
            $this->validateConfigBlock($configBlock);
            $path = $configBlock['path'];
            $hasRotatedLogs = $configBlock['hasRotatedLogs'];
            $rotatedLogsRegex = $configBlock['rotatedLogsRegex'];
            $agentReplaceCnt = 0;
            $fileSpec = str_replace('%agentKeyName%', $agentKeyName, $configBlock['fileSpec'], $agentReplaceCnt);
            if (!$hasAgentInfo && $agentReplaceCnt > 0) {
                // skip this log set if it requires an Agent key name but we don't have one set
                continue;
            }
            if (!$this->addMultipleFiles(
                $path . '/' . $fileSpec,
                $hasRotatedLogs,
                $rotatedLogsRegex,
                $includeRotatedLogs,
                $rotatedLogsDaysBack,
                $fileList
            )
            ) {
                // Could not add all of the intended files
                $this->truncated = true;
                break;
            }
        }
        return $fileList;
    }

    public function readConfig(string $configFilePath = SupportZip::ZIP_CONFIG_PATH): array
    {
        if (!$this->filesystem->isFile($configFilePath)) {
            throw new Exception('Configuration file ' . $configFilePath . ' does not exist.');
        }
        $configContent = $this->filesystem->fileGetContents($configFilePath);
        if (empty($configContent)) {
            throw new Exception('Could not read configuration file: ' . $configFilePath);
        }
        $returnedJson = json_decode($configContent, true);
        if (empty($returnedJson)) {
            throw new Exception('Could not decode json in configuration file: ' . $configFilePath);
        }
        return $returnedJson;
    }

    private function buildFilename(string $agentKeyName): string
    {
        return sprintf(
            SupportZip::ZIP_NAME_TEMPLATE,
            $this->hostname->get(),
            $agentKeyName,
            $this->dateTimeService->getTime()
        );
    }

    private function hasAgentInfo(string $agentKeyName): bool
    {
        if (strlen($agentKeyName) === 0) {
            return false;
        }
        if (!$this->agentService->exists($agentKeyName)) {
            throw new Exception('Invalid agent key name provided.');
        }
        return true;
    }

    private function validateConfigBlock(array $configBlock): void
    {
        $keys = [ 'path', 'fileSpec', 'hasRotatedLogs', 'rotatedLogsRegex' ];
        foreach ($keys as $keyKey => $keyVal) {
            if (!array_key_exists($keyVal, $configBlock)) {
                throw new Exception('Configuration missing \'' . $keyVal . '\' key.');
            }
        }
        $path = $configBlock['path'];
        if (!$this->filesystem->isDir($path)) {
            throw new Exception('Configured path does not exist: ' . $path);
        }
        $fileSpec = $configBlock['fileSpec'];
        if (empty($fileSpec)) {
            throw new Exception('Configured fileSpec is empty for path: ' . $path);
        }
    }

    /**
     * @return bool - true if the zip file was truncated or false if it was not
     */
    private function checkAndFixOversizedZip(string $entryName): bool
    {
        if ($this->filesystem->getSize($this->zipPath) <= $this->zipMaxSize) {
            return false;
        }
        // too big so remove file that was just added
        $zipRC = $this->zipArchive->open($this->zipPath);
        if (!($zipRC === true)) {
            throw new Exception(
                'Could not reopen existing zip file: ' . $this->zipPath . ' errcode: ' . intval($zipRC)
            );
        }
        $zipRC = $this->zipArchive->deleteName($entryName);
        $this->zipArchive->close();
        if (!$zipRC) {
            throw new Exception('Could not delete file: ' . $entryName . ' from zip: ' . $this->zipPath);
        }
        return true;
    }

    /**
     * @return bool - true if the file was added to the zip, false if not
     */
    private function addFileToZip(string $filePath): bool
    {
        $zipRC = $this->zipArchive->open(
            $this->zipPath,
            (!$this->filesystem->isFile($this->zipPath)) ? \ZipArchive::CREATE : 0
        );
        if (!($zipRC === true)) {
            throw new Exception(
                'Could not reopen existing zip file: ' . $this->zipPath . ' errcode: ' . intval($zipRC)
            );
        }
        $entryName = ltrim($filePath, '/');
        $zipRC = $this->zipArchive->addFile($filePath, $entryName);
        $this->zipArchive->close();
        if (!$zipRC) {
            throw new Exception('Could not add file: ' . $filePath . ' to zip: ' . $this->zipPath);
        }
        if ($this->checkAndFixOversizedZip($entryName)) {
            // zip file was truncated - last file removed
            return false;
        }
        return true;
    }

    /**
     * @return bool - true if the file should be added to the zip, false if not
     */
    private function shouldAddFile(
        string $filePath,
        bool $hasRotatedLogs,
        string $rotatedlogsRegex,
        bool $includeRotatedLogs,
        int $rotatedLogsDaysBack
    ): bool {
        if (!$hasRotatedLogs || !preg_match('/' . $rotatedlogsRegex . '/', $filePath)) {
            return true;
        }

        if (!$includeRotatedLogs) {
            return false;
        }

        $curTime = $this->dateTimeService->getTime();
        $fileMTime = $this->filesystem->fileMTime($filePath);
        if ($fileMTime === false) {
            throw new Exception('Could not get mtime for ' . $filePath);
        }
        $daysOld = intval(($curTime - $fileMTime) / (24 * 60 * 60));
        return ($daysOld <= $rotatedLogsDaysBack);
    }

    /**
     * @return bool - true if all of the target files were added to the zip, false if not
     */
    private function addMultipleFiles(
        string $pathFileSpec,
        bool $hasRotatedLogs,
        string $rotatedLogsRegex,
        bool $includeRotatedLogs,
        int $rotatedLogsDaysBack,
        array &$fileList
    ): bool {
        $files = $this->filesystem->glob($pathFileSpec);
        if (empty($files)) {
            return true;
        }
        foreach ($files as $filePath) {
            if ($this->filesystem->isFile($filePath)) {
                if ($this->shouldAddFile(
                    $filePath,
                    $hasRotatedLogs,
                    $rotatedLogsRegex,
                    $includeRotatedLogs,
                    $rotatedLogsDaysBack
                )
                ) {
                    if (!$this->addFileToZip($filePath)) {
                        return false;
                    }
                    $fileList[] = $filePath;
                }
            }
        }
        return true;
    }
}
