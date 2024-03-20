<?php

namespace Datto\Virtualization;

use Datto\Config\Virtualization\VirtualDisk;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Connection\Libvirt\HvConnection;
use Datto\Core\Network\DeviceAddress;
use Datto\Iscsi\IscsiTarget;
use Datto\Log\SanitizedException;
use Datto\Util\RetryHandler;
use Datto\Virtualization\Exceptions\HvIscsiCleanupException;
use Datto\Virtualization\Exceptions\HvIscsiException;
use Datto\Winexe\WinexeApi;
use Exception;
use Datto\Log\DeviceLoggerInterface;
use Throwable;

/**
 * Handles offload of virtual disks to Hyper-V hosts.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class HvRemoteStorage extends BaseRemoteStorage
{
    /**
     * Default timout for WinexeApi is 30s, since we're dealing with iSCSI here
     * the commands may take longer than that depending how busy the system is
     * and how many targets it already has. So for those commands bump timeout
     * to 180s.
     */
    const DEFAULT_TIMEOUT = 180;
    // Note: The disks mount serially, and each take about 5 seconds.
    // So this retry count is implicitly the max number of disks we support.
    // I am setting this to the maximum number of drive letters in Windows.
    const ISCSI_QUERY_ATTEMPTS_MAX = 26;
    const ISCSI_QUERY_DELAY_SEC = 5;
    const WIN_INVALID_DEVICE_NUMBER = 4294967295;

    private HvConnection $connection;
    private WinexeApi $winexeApi;
    private DeviceAddress $deviceAddress;
    private RetryHandler $retryHandler;

    public function __construct(
        HvConnection $connection,
        DeviceAddress $deviceAddress,
        IscsiTarget $iscsiTarget,
        WinexeApi $winexeApi,
        RetryHandler $retryHandler,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($iscsiTarget, $logger);
        $this->connection = $connection;
        $this->winexeApi = $winexeApi;
        $this->deviceAddress = $deviceAddress;
        $this->retryHandler = $retryHandler;
    }

    /**
     * @inheritdoc
     */
    public function offload(string $vmName, string $storageDir, bool $isEncrypted, VirtualDisks $disks): VirtualDisks
    {
        $this->logger->info('HVS1000 Starting Hyper-V storage offload via iSCSi');

        $remoteDisks = new VirtualDisks();

        $uniqueName = basename($storageDir);

        $targetName = $this->createLocalIscsiTarget($uniqueName, $disks);

        $connectionName = $this->connection->getName();
        $this->logger->info('HVS1005 Initializing iSCSI remote storage', [
            'vmName' => $vmName,
            'connectionName' => $connectionName,
            'targetName' => $targetName,
        ]);

        try {
            $diskInfo = $this->addHostIscsiTarget($targetName);

            $actualCount = count($diskInfo);
            $expectedCount = count($disks);
            if ($actualCount != $expectedCount) {
                throw new Exception("An error occurred offloading iscsi disks to Hyper-V host.  Expected $expectedCount disks, but actual count was $actualCount.");
            }

            foreach ($diskInfo as $disk) {
                $this->setHostDiskOffline($disk['DeviceNumber']);
                $remoteDisks->append(
                    new VirtualDisk(
                        $disk['DeviceNumber'],
                        $disk['DeviceNumber'],
                        '',
                        false
                    )
                );
            }
        } catch (Throwable $ex) {
            $ex = new SanitizedException($ex, [$this->connection->getUser(), $this->connection->getPassword()]);
            $this->logger->critical('HVS1100 iSCSI offload failed', ['exception' => $ex]);
            throw new HvIscsiException($ex);
        }

        $this->logger->info('HVS1001 iSCSI offload to Hyper-V complete');

        return $remoteDisks;
    }

    /**
     * @inheritdoc
     */
    public function tearDown(string $vmName, string $storageDir, bool $isEncrypted)
    {
        $this->logger->info('HVS1002 Tearing down storage offload', ['vmName' => $vmName]);

        $uniqueName = basename($storageDir);

        try {
            $targetName = $this->iscsiTarget->makeTargetName($uniqueName);
            $this->removeHostIscsiTarget($uniqueName, $targetName);

            if (!$this->iscsiTarget->doesTargetExist($targetName)) {
                $targetName = $this->findLegacyNamedIscsiTarget($uniqueName);
            }

            if (empty($targetName)) {
                $this->logger->warning(
                    'HVS1006 HVRemoteStorage Cleanup: iSCSI target not found.',
                    ['targetName' => $targetName]
                );
            } else {
                $connectionName = $this->connection->getName();
                $this->logger->info('HVS1007 Terminating iSCSI remote storage', [
                    'vmName' => $vmName,
                    'connectionName' => $connectionName,
                    'targetName' => $targetName,
                ]);
                $this->destroyLocalIscsiTarget($targetName);
            }
        } catch (Throwable $ex) {
            $ex = new SanitizedException($ex, [$this->connection->getUser(), $this->connection->getPassword()]);
            $this->logger->critical('HVS1102 iSCSI teardown failed', ['exception' => $ex]);
            throw new HvIscsiCleanupException($ex);
        }

        $this->logger->info('HVS1005 Storage offload tear down complete');
    }

    /**
     * Search iscsi targets using old naming convention
     *
     * @param string $uniqueName
     * @return string|null
     */
    private function findLegacyNamedIscsiTarget(string $uniqueName)
    {
        $existingTargets = $this->iscsiTarget->listTargets();

        // This class previously used iscsimounter to create iscsi targets, so for a short time, some existing
        // targets may have a different naming convention.
        // new style: iqn.2007-01.net.datto.dev.buffer-overflow:agent2587ec5e5f214bd5a02620d0ef6c5940-active
        // old style: iqn.2007-01.net.datto.dev.buffer-overflow:iscsimounter-0b56fe2658a74fc194ab0b1a389694a0-1534248083

        // ugly, parse out the agentKey
        // ex: 2587ec5e5f214bd5a02620d0ef6c5940-active
        $agentKey = explode('-', $uniqueName)[0] ?? '';
        if (!empty($agentKey)) {
            foreach ($existingTargets as $existingTarget) {
                if (preg_match("/:iscsimounter-$agentKey-\d+/m", $existingTarget)) {
                    return $existingTarget;
                }
            }
        }

        return null;
    }

    /**
     * Registers our iSCSI LUNs with Hyper-V host.
     *
     * @param string $targetName
     *
     * @return array an array with DeviceNumbers assigned by Hyper-V host to
     *  our LUNs.
     */
    private function addHostIscsiTarget(string $targetName)
    {
        $deviceIp = $this->deviceAddress->getLocalIp($this->connection->getHost());

        $this->runPowerShellCommand('Set-Service -Name MSiSCSI -StartupType Automatic');
        $this->runPowerShellCommand('Start-Service MSiSCSI');

        $command = sprintf(
            'New-IscsiTargetPortal -TargetPortaladdress %s',
            $deviceIp
        );

        $this->runPowerShellCommand($command);

        $command = sprintf(
            'Connect-IscsiTarget -NodeAddress %s',
            $targetName
        );

        try {
            $this->runPowerShellCommand($command);
        } catch (Throwable $x) {
            if (strpos($x->getMessage(), 'The target has already been logged in via an iSCSI session.') === false) {
                throw $x;
            }
        }

        $command = sprintf(
            'Get-WmiObject -Namespace root\WMI -Class ' .
            'MSiSCSIInitiator_SessionClass -Filter "TargetName=`\"%s`\"" | ' .
            'Select-Object -ExpandProperty Devices | ' .
            'Select-Object DeviceNumber | ConvertTo-Json',
            $targetName
        );

        // There are a few cases where retrying here is necessary...
        // 1. There is a race between Windows assigning a DeviceNumber, and us querying the assignment, so retry
        //    if invalid device number is returned for any disk.
        // 2. If the Vm is not fully *alive* runPowerShellCommand() could just fail to execute Get-WmiObject, and throw
        //    an exception.
        // 3. If the Vm is not fully *alive* runPowerShellCommand() could execute Get-WmiObject but a zero length
        //    array returned.
        $disks = $this->retryHandler->executeAllowRetry(
            function () use ($command) {
                $jsonOut = $this->runPowerShellCommand($command);
                $out = json_decode($jsonOut, true);

                if ($out) {
                    // If there's just one Drive, the PS command will return that object
                    // If there's > 1 drive, it will be array of objects.
                    // So thanks to this we need to settle on one format.. that is:
                    // array of objects (arrays) or nothing, period.
                    if (count($out) === 1) {
                        $out = array($out);
                    }
                } else {
                    $out = [];
                }

                //  If we detect a disk has an invalid DeviceNumber, trigger a retry
                if (count($out) === 0) {
                    throw new Exception('Windows has not returned any iscsi disks');
                } else {
                    foreach ($out as $disk) {
                        if ($disk["DeviceNumber"] === self::WIN_INVALID_DEVICE_NUMBER) {
                            throw new Exception('Windows has not assigned a DeviceNumber to the iscsi disk');
                        }
                    }
                }
                return $out;
            },
            self::ISCSI_QUERY_ATTEMPTS_MAX,
            self::ISCSI_QUERY_DELAY_SEC,
            false
        );

        return $disks;
    }

    /**
     * Remove iscsi target from HyperV host
     *
     * @param string $storageDirName
     * @param string $targetName
     */
    private function removeHostIscsiTarget(string $storageDirName, string $targetName)
    {
        // This class previously used iscsimounter to create iscsi targets, so for a short time, some existing
        // targets may have a different naming convention.
        // new style: iqn.2007-01.net.datto.dev.buffer-overflow:agent2587ec5e5f214bd5a02620d0ef6c5940-active
        // old style: iqn.2007-01.net.datto.dev.buffer-overflow:iscsimounter-0b56fe2658a74fc194ab0b1a389694a0-1534248083

        // ugly, parse out the agentKey
        // ex: 2587ec5e5f214bd5a02620d0ef6c5940-active
        $agentKey = explode('-', $storageDirName)[0] ?? '';

        // use regex to match either style of name
        $powershellRegex = "$targetName";
        if (!empty($agentKey)) {
            // first part of target name
            // ex: iqn.2007-01.net.datto.dev.buffer-overflow
            $targetStart = explode(':', $targetName)[0];
            $powershellRegex = "($targetName|$targetStart:iscsimounter-$agentKey-\d+)";
        }

        try {
            $command = sprintf(
                'Get-IscsiTarget | Where-Object NodeAddress -match \"%s\" | Disconnect-IscsiTarget -Confirm:$False',
                $powershellRegex
            );

            $this->runPowerShellCommand($command, true);

            $command = sprintf(
                'Remove-IscsiTargetPortal -TargetPortalAddress %s -Confirm:$False',
                $this->deviceAddress->getLocalIp($this->connection->getHost())
            );

            $this->runPowerShellCommand($command, true);
        } catch (Throwable $ex) {
            $ex = new SanitizedException($ex, [$this->connection->getUser(), $this->connection->getPassword()]);

            /* while we could not cleanup after ourselves on the Hyper-V
             * host for some reason, make sure we continue cleaning up at
             * least on the device side.
             */
            $this->logger->critical('HVS1101 Failed to remove iSCSI target', [
                'targetName' => $targetName,
                'connectionHost' => $this->connection->getHostname(),
                'exception' => $ex
            ]);
        }
    }

    /**
     * @param $diskNumber
     */
    private function setHostDiskOffline($diskNumber)
    {
        $command = sprintf(
            'Set-Disk -Number %d -IsOffline $True -ErrorAction SilentlyContinue',
            $diskNumber
        );

        try {
            $this->runPowerShellCommand($command);
        } catch (Throwable $ex) {
            /* Ignore failure: this is expected to fail in most cases as disks
             * are already set offline by default. This explicit call was added
             * due to observed corner-cases where it's not so.
             */
        }
    }

    /**
     * Runs a PowerShell command via winexe API.
     *
     * When called with tolerant = true, it will catch ObjectNotFound PS errors
     * and ignore them (logged as warning). This is for commands that perform
     * 'cleanup' tasks where they may be trying to remove things that are already
     * gone - which obviously is not a problem. This function also logs any
     * PowerShell command it's attempting run to the debug log.
     *
     * @param string $command
     * @param bool $tolerant whether catch and ignore certain errors, false by
     *  default.
     *
     * @return string output of the PowerShell command, if any (may be empty)
     *
     * @throws Throwable any exception that is not handled in tolerant mode.
     */
    private function runPowerShellCommand($command, $tolerant = false)
    {
        try {
            $this->logger->debug(sprintf('HVS1010 Running PowerShell command: %s', $command));
            $result = $this->winexeApi->runPowerShellCommand($command, self::DEFAULT_TIMEOUT);
            $this->logger->debug(sprintf('HVS1011 PowerShell result: %s', $result));
            return $result;
        } catch (Throwable $ex) {
            $this->logger->error('HVS1003 PowerShell exception', ['exception' => $ex->getMessage()]);
            if ($tolerant === true) {
                $msg = $ex->getMessage();
                $objectNotFound = preg_match('/CategoryInfo\s+ObjectNotFound/i', $msg);

                if ($objectNotFound !== false) {
                    $this->logger->warning(
                        'HVS1004 The command failed with ObjectNotFound - ignoring...'
                    );

                    return '';
                }
            }

            throw $ex;
        }
    }

    /**
     * Adds notes to VM in Hyper-V manager.
     *
     * @param $vmName
     * @param $assetName
     * @param $snapshot
     * @param $deviceId
     * @param $iscsiTargetHost
     */
    public function addNotes(string $vmName, string $assetName, int $snapshot, int $deviceId, string $iscsiTargetHost) : void
    {
        // Run PowerShell command to specify notes to the given VM
        $command = "Set-VM -Name $vmName -Notes \\\"Device ID: $deviceId`nAsset name: $assetName`nSnapshot: $snapshot`nISCSI Target Host: $iscsiTargetHost\\\"";
        $this->runPowerShellCommand($command, true);
    }
}
