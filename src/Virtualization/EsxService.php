<?php

namespace Datto\Virtualization;

use Datto\Asset\Share\CreateShareService;
use Datto\Asset\Share\Nas\NasShareBuilderFactory;
use Datto\Asset\Share\Share;
use Datto\Asset\Share\ShareService;
use Datto\Connection\Libvirt\EsxHostType;
use Datto\Common\Utility\Filesystem;
use Datto\Resource\DateTimeService;
use DiagnosticManagerLogDescriptor;
use DiagnosticManagerLogHeader;
use Datto\Connection\Service\EsxConnectionService;
use Exception;
use ServiceContent;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Vmwarephp\Vhost;

/**
 * Provides services for ESX Hosts
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class EsxService
{
    const SHARE_PATH = '/datto/mounts/';
    const LINES_PER_FILE_READ = 1000;
    const FORBIDDEN_FILENAME_CHARACTERS = ": | , ! ? * \\ / ' \" < >";

    /** @var CreateShareService */
    private $createShareService;

    /** @var Filesystem Filesystem operations */
    private $filesystem;

    /** @var ShareService Services for managing shares */
    private $shareService;

    /** @var NasShareBuilderFactory Factory for creating NasShareBuilder objects */
    private $nasShareBuilderFactory;

    /** @var VhostFactory Factory for creating VHost objects */
    private $vhostFactory;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    public function __construct(
        CreateShareService $createShareService,
        Filesystem $filesystem,
        ShareService $shareService,
        NasShareBuilderFactory $nasShareBuilderFactory,
        VhostFactory $vhostFactory,
        DateTimeService $dateTimeService,
        EsxConnectionService $esxConnectionService
    ) {
        $this->createShareService = $createShareService;
        $this->filesystem = $filesystem;
        $this->shareService = $shareService;
        $this->nasShareBuilderFactory = $nasShareBuilderFactory;
        $this->vhostFactory = $vhostFactory;
        $this->dateTimeService = $dateTimeService;
        $this->esxConnectionService = $esxConnectionService;
    }

    /**
     * Get all logs for the specified connection
     *
     * @param string $connectionName name of the connection for which to list the logs
     * @return EsxLogFile[] Array of objects specifying ESX log files
     */
    public function getAllLogFiles($connectionName)
    {
        $vhost = $this->vhostFactory->create($connectionName);
        $diagnosticManager = $this->getHostDiagnosticManager($vhost);
        $esxConnection = $this->esxConnectionService->get($connectionName);
        if ($esxConnection->getHostType() == EsxHostType::STANDALONE) {
            $ret = $vhost->findAllManagedObjects('HostSystem', array());
            $host = empty($ret) ? null : $ret[0];
        } else {
            $host = $vhost->findManagedObjectByName(
                'HostSystem',
                $esxConnection->getEsxHost(),
                array()
            );
        }
        $logs = array();
        if ($host) {
            $params = array(
                'HostSystem' => $host->toReference()
            );
            /** @var DiagnosticManagerLogDescriptor[] $logList */
            $logList = $diagnosticManager->QueryDescriptions($params);
            foreach ($logList as $log) {
                $logs[] = new EsxLogFile($log->fileName, $log->key);
            }
        }
        return $logs;
    }

    /**
     * Download a single log file from an ESX host and save it on the device
     *
     * @param string $connectionName ESX host connection name
     * @param string $log key of the log file
     * @param string $shareName share to save file in
     * @param bool $createNewShare whether the share must be created
     * @return int the number of lines downloaded
     */
    public function downloadLogFile($connectionName, $log, $shareName, $createNewShare)
    {
        $vhost = $this->vhostFactory->create($connectionName);
        $diagnosticManager = $this->getHostDiagnosticManager($vhost);
        $hasReachedTheEnd = false;
        $destinationFile = $this->filterFilename(
            sprintf("esxHostLogs-%s-%s-%d", $connectionName, $log, $this->dateTimeService->getTime())
        );
        $handle = $this->openLogShareFile($destinationFile, $shareName, $createNewShare);
        $startLine = 1;

        do {
            try {
                /** @var DiagnosticManagerLogHeader $logObject */
                $logObject = $diagnosticManager->browseDiagnosticLog(
                    array('key' => $log, 'start' => $startLine, 'lines' => self::LINES_PER_FILE_READ)
                );
            } catch (Exception $exception) {
                break;
            }

            foreach ($logObject->lineText as $line) {
                $bytesWritten = $this->filesystem->write($handle, "$line\n");
                if ($bytesWritten === false) {
                    throw new FileException('Could not write log to ' . $destinationFile);
                }
            }

            $newLineCount = count($logObject->lineText);
            $hasReachedTheEnd = $newLineCount < self::LINES_PER_FILE_READ;
            $startLine = $startLine + $newLineCount;
        } while (!$hasReachedTheEnd);

        $this->filesystem->close($handle);

        return $startLine - 1;
    }

    /**
     * Get a DiagnosticManager object
     *
     * @param Vhost $vhost vhost obtained from the connection name
     * @return mixed the diagnostic manager
     */
    private function getHostDiagnosticManager($vhost)
    {
        /** @var ServiceContent $serviceContent */
        $serviceContent = $vhost->getServiceContent();
        return $serviceContent->diagnosticManager;
    }

    /**
     * Open a log file on the specified share optionally creating the share if it doesn't exist
     *
     * @param string $fileName Name of the file to write
     * @param string $shareName Name of the share to write
     * @param boolean $createShare True to create the share if it does not exist, False otherwise
     * @return resource Handle for use with fwrite to write to log share. User must close.
     */
    private function openLogShareFile($fileName, $shareName, $createShare = false)
    {
        if (!$this->shareService->exists($shareName)) {
            if ($createShare) {
                $this->createLogShare($shareName);
            } else {
                throw new Exception("Share $shareName does not exist. Please create it or use the -c option.");
            }
        }
        $handle = $this->filesystem->open(self::SHARE_PATH . "$shareName/$fileName", 'w');
        if ($handle === false) {
            throw new Exception("Unable to open $fileName for writing.");
        }
        return $handle;
    }

    /**
     * Create a share if it does not already exist
     *
     * @param string $shareName
     */
    private function createLogShare($shareName)
    {
        if (!$this->shareService->exists($shareName)) {
            $builder = $this->nasShareBuilderFactory->create($shareName);
            $share = $builder->build();
            $this->createShareService->create($share, Share::DEFAULT_MAX_SIZE);
        }
    }

    /**
     * Remove potentially problematic characters from the filename (may be in share name or log key name)
     *
     * @param string $file input filename
     * @return string file name with all problematic characters replaced by underscores
     */
    private function filterFilename($file)
    {
        $forbiddenCharacters = explode(' ', self::FORBIDDEN_FILENAME_CHARACTERS);
        foreach ($forbiddenCharacters as $forbiddenCharacter) {
            $file = str_replace($forbiddenCharacter, '_', $file);
        }
        return $file;
    }
}
