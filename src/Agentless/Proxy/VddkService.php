<?php

namespace Datto\Agentless\Proxy;

use Datto\Common\Resource\ProcessFactory;
use Datto\Resource\FilesystemAttributes;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Utility\Filesystem;
use Datto\Util\RetryHandler;
use Datto\Common\Resource\Sleep;
use Datto\Log\DeviceLoggerInterface;

/**
 * Provides vddk related operations like: mountVddk, umountVddk...
 *
 * @author Mario Rial <mrial@datto.com>
 */
class VddkService
{
    public const VDDK_FUSE_COMMAND = 'vddk-fuse';
    public const VDDK_FUSE_MOUNT_COMMAND = 'vddk-mount';
    public const VDDK_TMP_DIRECTORY_FORMAT = '/tmp/vmware-root/%s-%s';
    public const VDDK_TRANSPORT_ATTRIBUTE = 'vddk.transport';
    public const VDDK_VERSION_6_7 = '6.7';
    public const VDDK_OPTIONS_FORMAT =
        'host=%s,vm_id=%s,snapshot_id=%s,ssl_thumb=%s,force_nbd=%d,sdk_ver=%s,allow_other';
    public const MKDIR_MODE = 0777;

    private const UNKNOWN_TRANSPORT_METHOD = 'unknown';
    private const VDDK_CLEANUP_TIMEOUT_SECONDS = 180;
    private const VDDK_VERSION_6_0 = '6.0';

    private RetryHandler $retryHandler;
    private Filesystem $filesystem;
    private FilesystemAttributes $filesystemAttributes;
    private PosixHelper $posixHelper;
    private ProcessFactory $processFactory;
    private Sleep $sleep;


    public function __construct(
        RetryHandler $retryHandler,
        Filesystem $filesystem,
        FilesystemAttributes $filesystemAttributes,
        PosixHelper $posixHelper,
        ProcessFactory $processFactory,
        Sleep $sleep
    ) {
        $this->retryHandler = $retryHandler;
        $this->filesystem = $filesystem;
        $this->filesystemAttributes = $filesystemAttributes;
        $this->posixHelper = $posixHelper;
        $this->processFactory = $processFactory;
        $this->sleep = $sleep;
    }

    /**
     * Mounts remote VMDKs on a local filesystem.
     *
     * @param string $host
     * @param string $user
     * @param string $password
     * @param string $vmMoRefId
     * @param string $snapshotMoRefId
     * @param array $vmdkDisksPaths
     * @param string $vddkMountPoint
     * @param string $esxHostVersion
     * @param DeviceLoggerInterface $logger
     * @param bool $forceNbd
     * @return string
     *  A path to the local mount point.
     */
    public function mountVddk(
        string $host,
        string $user,
        string $password,
        string $vmMoRefId,
        string $snapshotMoRefId,
        array $vmdkDisksPaths,
        string $vddkMountPoint,
        string $esxHostVersion,
        DeviceLoggerInterface $logger,
        bool $forceNbd = false
    ) {
        $this->filesystem->mkdirIfNotExists($vddkMountPoint, false, self::MKDIR_MODE);

        $hostSslFingerprint = $this->retrieveEsxHostSSLFingerprint($host);
        $selectedVddkVersion = $this->getBestVddkVersionForEsxHost($esxHostVersion);

        $logger->info('VDK0000 Mounting vddk-fuse', ['selectedVddkVersion' => $selectedVddkVersion]);

        $command = [
            self::VDDK_FUSE_COMMAND,
            '-o',
            sprintf(
                self::VDDK_OPTIONS_FORMAT,
                $host,
                $vmMoRefId,
                $snapshotMoRefId,
                $hostSslFingerprint,
                $forceNbd ? 1 : 0,
                $selectedVddkVersion
            )
        ];
        $env = [
            'ESX_USERNAME' => $user
        ];

        foreach ($vmdkDisksPaths as $vmdkDiskPath) {
            $command[] = $vmdkDiskPath;
        }

        $command[] = $vddkMountPoint;

        $process = $this->processFactory
            ->get($command, null, null, $password);

        $process->mustRun(null, $env);

        return $this->retrieveVddkFusePid($vddkMountPoint);
    }

    /**
     * If ESX host version >= 6.0 we use VDDK 6.7 use 6.0 otherwise.
     *
     * @param string $esxHostVersion
     * @return string
     */
    private function getBestVddkVersionForEsxHost(string $esxHostVersion)
    {
        if (version_compare($esxHostVersion, self::VDDK_VERSION_6_0, '>=')) {
            return self::VDDK_VERSION_6_7;
        }

        return self::VDDK_VERSION_6_0;
    }

    /**
     * @param string $mountPoint
     * @return bool
     */
    private function isFuseVddkInMtab(string $mountPoint)
    {
        $process = $this->processFactory->get(['findmnt', $mountPoint]);
        $process->run();
        return $process->getExitCode() === 0;
    }

    /**
     * Umounts the remote VMDK from a local filesystem.
     *
     * @param string $mountPoint
     * @param DeviceLoggerInterface $logger
     */
    public function umountVddk(string $mountPoint, DeviceLoggerInterface $logger): void
    {
        $vddkFusePid = null;

        try {
            $vddkFusePid = $this->retrieveVddkFusePid($mountPoint);
        } catch (\Throwable $exception) {
        }

        $isFuseVddkInMtab = $this->isFuseVddkInMtab($mountPoint);

        if ($vddkFusePid && $isFuseVddkInMtab) {
            //Good path
            $logger->info('VDK0001 Vddk-fuse found running, umounting with fusermount...');
            $this->retryHandler->executeAllowRetry(
                function () use ($mountPoint) {
                    $process = $this->processFactory->get(['fusermount', '-u', $mountPoint]);
                    $process->mustRun();
                }
            );
            // wait for vddk-fuse to finish cleanup and terminate
            // fusermount call above will delete the mountpoint but cleanup
            // is still being done in the background.
            $this->waitForPidToDie($vddkFusePid, self::VDDK_CLEANUP_TIMEOUT_SECONDS, $logger);
        } elseif ($isFuseVddkInMtab) {
            $logger->warning('VDK0002 Vddk-fuse was not found running, but was still in the mtab, it was abruptly killed');
            $this->retryHandler->executeAllowRetry(
                function () use ($mountPoint) {
                    $process = $this->processFactory->get(['umount', $mountPoint]);
                    $process->mustRun();
                }
            );
            throw new \RuntimeException('Vddk-fuse mounted but process was not running.');
        } elseif ($vddkFusePid) {
            $logger->warning('VDK0003 Vddk-fuse was found running without any mountpoint, nothing to unmount.');
            throw new \RuntimeException('Vddk-fuse running without mountpoint, nothing to unmount.');
        } else {
            throw new \RuntimeException(
                'VDK0004 Vddk-fuse mountpoints are unexpectedly clean, nothing to unmount.'
            );
        }
    }

    /**
     * @param int $pid
     * @param int $seconds
     * @param DeviceLoggerInterface $logger
     */
    private function waitForPidToDie(int $pid, int $seconds, DeviceLoggerInterface $logger): void
    {
        $waitedSeconds = 0;
        $logger->info('VDK0005 Waiting for vddk-fuse process to die', ['timeoutSeconds' => $seconds, 'pid' => $pid]);
        while ($this->posixHelper->isProcessRunning($pid)) {
            // wait for 3 minutes - usually terminates in a few s.
            if ($waitedSeconds > $seconds) {
                break;
            }
            $this->sleep->sleep(1);
            $waitedSeconds++;
        }

        if ($this->posixHelper->isProcessRunning($pid)) {
            $logger->error('VDK0006 Process still running after timeout', ['pid' => $pid]);
            throw new \Exception("Vddk-fuse process with pid $pid still running after timeout");
        }
    }

    /**
     * @param string $vddkMountPath
     * @return string
     */
    public function retrieveVddkFusePid(string $vddkMountPath)
    {
        // Find PID of the vddk-fuse process.The "& echo $!" won't work  as the
        // process forks, so pgrep is the only choice
        $searchRegex = '^' . self::VDDK_FUSE_MOUNT_COMMAND . '.*' . $vddkMountPath;
        $process = $this->processFactory->get(['pgrep', '-f', $searchRegex]);
        $process->mustRun();
        return trim($process->getOutput());
    }

    /**
     * @param string $vddkMountPath
     * @param string $vmMoRefId
     * @param string $vmBiosUuid
     * @param DeviceLoggerInterface $logger
     */
    public function ensureVddkIsCleaned(
        string $vddkMountPath,
        string $vmMoRefId,
        string $vmBiosUuid,
        DeviceLoggerInterface $logger
    ): void {
        try {
            $vddkFusePid = (int)$this->retrieveVddkFusePid($vddkMountPath);
        } catch (\Throwable $throwable) {
            $vddkFusePid = null;
        }

        if ($vddkFusePid) {
            $this->forceKillVddk($vddkFusePid, $logger);
        }

        $this->cleanVddkVmTmpDirectories($vmMoRefId, $vmBiosUuid, $logger);
    }

    /**
     * @param int $vddkFusePid
     * @param DeviceLoggerInterface $logger
     */
    private function forceKillVddk(int $vddkFusePid, DeviceLoggerInterface $logger): void
    {
        $this->retryHandler->executeAllowRetry(
            function () use ($vddkFusePid, $logger) {
                $logger->warning('VDK0007 Vddk-fuse is still running, trying to kill.');
                $this->posixHelper->kill($vddkFusePid, 9);
                if ($this->posixHelper->isProcessRunning($vddkFusePid)) {
                    $logger->warning('VDK0008 Vddk-fuse process still alive.', ['pid' => $vddkFusePid]);
                    throw new \Exception('Vddk-fuse process still alive.');
                }

                $logger->info('VDK0009 Vddk-fuse process killed');
            }
        );
    }

    /**
     * @param string $vmMoRefId
     * @param string $vmBiosUuid
     * @param DeviceLoggerInterface $logger
     */
    private function cleanVddkVmTmpDirectories(string $vmMoRefId, string $vmBiosUuid, DeviceLoggerInterface $logger): void
    {
        $vddkTmpPath = sprintf(self::VDDK_TMP_DIRECTORY_FORMAT, $vmBiosUuid, $vmMoRefId);
        $logger->debug('VDK0010 Cleaning vddk tmp directories', ['vddkTmpPath' => $vddkTmpPath]);

        if ($this->filesystem->exists($vddkTmpPath)) {
            $logger->info('VDK0011 Found vddk tmp directory, deleting...', ['vddkTmpPath' => $vddkTmpPath]);

            $this->retryHandler->executeAllowRetry(
                function () use ($vddkTmpPath) {
                    $process = $this->processFactory->get(['rm', '-rf', $vddkTmpPath]);
                    $process->mustRun();
                }
            );
            $logger->info('VDK0012 Successfully deleted.');
        } else {
            $logger->info('VDK0013 Vddk tmp directory was clean for specified VM.');
        }
    }

    /**
     * @param string $vddkMountPath
     * @return bool
     */
    public function isVddkMounted(string $vddkMountPath): bool
    {
        try {
            $this->retrieveVddkFusePid($vddkMountPath);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * @param string $vddkMountPath
     * @return array
     */
    public function getVmdksTransportMethods(string $vddkMountPath): array
    {
        if (!$this->isVddkMounted($vddkMountPath)) {
            throw new \Exception("Vddk is not mounted on $vddkMountPath");
        }

        $vmdks = [];
        foreach ($this->filesystem->glob($vddkMountPath . '/*') as $vmdkFilePath) {
            $attribute = $this->filesystemAttributes->getExtendedAttribute($vmdkFilePath, self::VDDK_TRANSPORT_ATTRIBUTE);
            $transportMethod = $attribute ?: self::UNKNOWN_TRANSPORT_METHOD;
            $vmdks[] = [
                'vmdk' => $vmdkFilePath,
                'transport' => $transportMethod
            ];
        }

        return $vmdks;
    }

    /**
     * @param string $host
     * @return string
     */
    private function retrieveEsxHostSSLFingerprint(string $host)
    {
        $process = $this->processFactory
            ->getFromShellCommandLine(
                'openssl s_client -connect "${:HOST}" < /dev/null 2>/dev/null | ' .
                "openssl x509 -fingerprint -noout -in /dev/stdin | sed -e 's/SHA1 Fingerprint=//'"
            );

        $process->setTimeout(5); // 5s to wait for connection should be more than enough.
        $process->run(null, ['HOST' => "$host:443"]);

        if ($process->getExitCode() !== 0) {
            throw new \RuntimeException('Failed to lookup SSL thumbprint ' . $process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
