<?php

namespace Datto\Virtualization;

use Datto\Connection\Exceptions\ConnectionCreateException;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Common\Resource\CurlRequest;
use Datto\Log\LoggerAwareTrait;
use Datto\Resource\DateTimeService;
use Datto\Common\Resource\Sleep;
use Datto\Common\Utility\Filesystem;
use Datto\Log\DeviceLoggerInterface;
use Datto\Util\RetryHandler;
use Datto\Virtualization\Exceptions\BypassedVcenterException;
use Exception;
use GuzzleHttp\Client;
use Psr\Log\LoggerAwareInterface;
use RuntimeException;
use Throwable;
use VirtualDevice;
use VirtualDisk;
use VirtualDiskRawDiskMappingVer1BackingInfo;
use VirtualMachineTicket;
use Vmwarephp\Extensions\Datastore;
use Vmwarephp\Extensions\VirtualMachine;
use Vmwarephp\ManagedObject;
use Vmwarephp\Vhost;

/**
 * Interacts with the VMware SOAP api.
 *
 * @author Mario Rial <mrial@datto.com>
 */
class VmwareApiClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CONNECTION_TYPE_ESX = 'HostAgent';
    public const CONNECTION_TYPE_VCENTER = 'VirtualCenter';

    private const UNKNOWN_UUID = 'unknown-uuid';

    private const DATASTORE_PATH_FORMAT = 'https://%s/folder/%s';

    private const REFERENCE_TYPE_DATACENTER = 'Datacenter';

    private const API_TIMEOUT_SECONDS = 120;

    private const SNAPSHOT_FAILURE_WAIT_SEC = 45;
    private const SNAPSHOT_ATTEMPT_COUNT = 2;

    /** @var Sleep */
    private $sleep;

    /** @var RetryHandler */
    private $retryHandler;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Client */
    private $httpClient;

    /** @var CurlRequest */
    private $curlRequest;

    /** @var int Used by uploadFile() */
    private $time;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        Sleep $sleep,
        RetryHandler $retryHandler,
        DateTimeService $dateTimeService,
        Client $httpClient,
        CurlRequest $curlRequest,
        Filesystem $filesystem
    ) {
        $this->sleep = $sleep;
        $this->retryHandler = $retryHandler;
        $this->dateTimeService = $dateTimeService;
        $this->httpClient = $httpClient;
        $this->curlRequest = $curlRequest;
        $this->filesystem = $filesystem;
        $this->time = 0;
    }

    /**
     * Get VM instance.
     *
     * @param Vhost $virtualizationHost
     * @param string $virtualMachineName
     *
     * @return VirtualMachine
     */
    public function retrieveVirtualMachine(Vhost $virtualizationHost, string $virtualMachineName)
    {
        $ret = preg_match('/^((vm-)?\d+)-?/', $virtualMachineName, $match);
        $virtualMachine = null;

        $exceptionMessage = 'Vmware could not lookup VM by provided name or ID: ' . $virtualMachineName;

        try {
            if ($ret) {
                $vmMoRef = $match[1];
                $virtualMachine = $virtualizationHost->findOneManagedObject(
                    'VirtualMachine',
                    $vmMoRef,
                    ['name', 'config.changeTrackingEnabled']
                );
            } else {
                $virtualMachine = $virtualizationHost->findManagedObjectByName(
                    'VirtualMachine',
                    $virtualMachineName,
                    ['name', 'config.changeTrackingEnabled']
                );
            }
        } catch (Exception $ex) {
            $this->logger->error('VAC0008 Vmware could not lookup VM by provided name or ID', [
                'exception' => $ex,
                'name or ID' => $virtualMachineName
            ]);

            if (strpos($ex->getMessage(), 'Cannot complete login') !== false) {
                $exceptionMessage .= ", login error.";
            }
            throw new ConnectionCreateException($exceptionMessage);
        }

        if (empty($virtualMachine)) {
            throw new ConnectionCreateException($exceptionMessage);
        }

        return $virtualMachine;
    }

    /**
     * Retrieves virtual machine vmx file contents.
     *
     * @param Vhost $vhost
     * @param string $vmMoRef
     * @return string
     */
    public function retrieveVirtualMachineVmx(Vhost $vhost, string $vmMoRef)
    {
        $virtualMachine = $this->retrieveVirtualMachine($vhost, $vmMoRef);

        $vmxFullPath = $virtualMachine->config->files->vmPathName;

        if (preg_match('/^\[(?P<datastore>[^\[\]]*)\] (?P<vmxpath>.*)$/', $vmxFullPath, $matches) !== 1) {
            throw new Exception("Invalid vmx path: $vmxFullPath");
        }

        $datastoreName = $matches['datastore'];
        $vmxPath = $matches['vmxpath'];
        $parentDatastore = null;

        foreach ($virtualMachine->datastore as $datastore) {
            if ($datastore->name === $datastoreName) {
                $parentDatastore = $datastore;
            }
        }

        if (!$parentDatastore) {
            throw new RuntimeException("Couldn't find parent datastore of vm: $vmMoRef");
        }

        $dcPath = $this->getDatacenterPathOfDatastore($parentDatastore);

        $url = sprintf(self::DATASTORE_PATH_FORMAT, $vhost->host, $vmxPath);

        $response = $this->httpClient->request(
            'GET',
            $url,
            [
                'query' => ['dcPath' => $dcPath, 'dsName' => $datastoreName],
                'auth' => [$vhost->username, $vhost->password],
                'verify' => false
            ]
        );

        return $response->getBody()->getContents();
    }

    /**
     * @param $datastore
     * @return string
     */
    private function getDatacenterPathOfDatastore(Datastore $datastore)
    {
        /** @var ManagedObject $parent */
        $parent = $datastore->parent;

        $startTime = $this->dateTimeService->getTime();

        while ($parent->getReferenceType() !== self::REFERENCE_TYPE_DATACENTER) {
            $parent = $parent->parent;

            if (!isset($parent)) {
                throw new RuntimeException("Datastore doesn't have a parent unexpectedly.");
            }

            if ($this->dateTimeService->getElapsedTime($startTime) > self::API_TIMEOUT_SECONDS) {
                throw new RuntimeException("Timeout reached while using vmware api.");
            }
        }

        $path = [];

        while (isset($parent)) {
            $path[] = $parent->name;
            $parent = $parent->parent;

            if ($this->dateTimeService->getElapsedTime($startTime) > self::API_TIMEOUT_SECONDS) {
                throw new RuntimeException("Timeout reached while using vmware api.");
            }
        }

        // Remove generic root directory, not needed in dcPath.
        array_pop($path);

        return implode('/', array_reverse($path));
    }

    /**
     * Enables Change Block Tracking for a VM, if it's not enabled.
     *
     * @param VirtualMachine $virtualMachine
     *  The VirtualMachine ManagedObject obtained via Vmwarephp API.
     *
     * @param bool $enable
     * @return bool
     *  False, if CBT was already enabled, true if it was enabled in this call,
     *  in which case VM must go through "stun-unstun" cycle for the change to
     *  be applied (taking snapshot, should take care of it according to docs)
     *
     */
    public function setCbtEnabled(VirtualMachine $virtualMachine, bool $enable = true)
    {
        if ($virtualMachine->config->changeTrackingEnabled === $enable) {
            return false;
        }

        $spec = new \VirtualMachineConfigSpec();
        $spec->changeTrackingEnabled = $enable;

        $task = $virtualMachine->ReconfigVm_Task(['spec' => $spec]);
        $ret = $this->waitForTask($task);

        if ($ret === false) {
            $moRef = $virtualMachine->toReference();
            throw new RuntimeException(
                sprintf('Failed to enable CBT for VM (%s)', $moRef->_)
            );
        }

        return true;
    }

    /**
     * Removes orphaned snapshots created by Siris for the VM. For historical reasons
     * snapshots created by agentless are called 'vSiris Backup <timestamp>'.
     *
     * The use case is when trying to recover from corrupted CBT where the
     * procedure mandates removal of all VM snapshots. Since any user created
     * snapshots should NOT be removed without user's consent, this method removes
     * only snapshots that it can identify as created by agentless backup. When
     * CBT recovery fails, this event is logged and suggests manual recovery.
     *
     * @param VirtualMachine $virtualMachine
     * @param DeviceLoggerInterface $logger
     * @return int
     *  Count of non-agentless snapshots left.
     */
    public function removeOrphanedSnapshots(VirtualMachine $virtualMachine, DeviceLoggerInterface $logger)
    {
        $snapshotsToRemove = [];
        $snapsRemoved = 0;

        $snapshot = $virtualMachine->snapshot;
        $snap = $snapshot->rootSnapshotList[0] ?? null;

        while (!empty($snap)) {
            $snapshotsToRemove[] = $snap;
            $snap = $snap->childSnapshotList[0] ?? null;
        }

        // sort from leaf to root
        $snapshotsToRemove = array_reverse($snapshotsToRemove);

        foreach ($snapshotsToRemove as $snap) {
            // only remove snapshots created by agentless
            $isSirisSnapshot = preg_match('/^vSiris Backup \d{10}$/', $snap->name);
            $timestamp = str_replace('vSiris Backup ', '', $snap->name);
            // if valid timestamp and created over 1h ago
            $canRemove = $isSirisSnapshot > 0 && $timestamp < $this->dateTimeService->getTime() - 3600;

            if ($canRemove) {
                $task = $snap->snapshot->RemoveSnapshot_Task(['removeChildren' => false]);
                $ret = $this->waitForTask($task);

                // if it failed, continue removing, just log the event.
                if (false === $ret) {
                    $logger->warning('VAC0000 Failed to remove VM snapshot', ['snapshot' => $snap->snapshot->_]);
                } else {
                    $snapsRemoved++;
                }
            }
        }

        return count($snapshotsToRemove) - $snapsRemoved;
    }

    /**
     * Attempt to create vm snapshot
     * Retry after 45 second sleep on failure due to intermittent quiescing error
     *
     * @param VirtualMachine $virtualMachine
     * @return ManagedObject
     */
    public function createSnapshot(VirtualMachine $virtualMachine): ManagedObject
    {
        $count = 0;
        do {
            $count++;
            // this will also make CBT enablement effective.
            $task = $virtualMachine->CreateSnapshot_Task([
                'name' => 'vSiris Backup ' . $this->dateTimeService->getTime(),
                'description' => 'vSiris snapshot for backup',
                'memory' => false,
                'quiesce' => true,
            ]);
            $snapshot = $this->waitForTask($task);
            if ($snapshot === false && $count < self::SNAPSHOT_ATTEMPT_COUNT) {
                $this->sleep->sleep(self::SNAPSHOT_FAILURE_WAIT_SEC);
            }
        } while ($count < self::SNAPSHOT_ATTEMPT_COUNT && $snapshot === false);

        if ($snapshot === false) {
            throw new RuntimeException(
                'Failed to create VM snapshot after second attempt - aborting'
            );
        }

        return $snapshot;
    }

    /**
     * @param VirtualMachine $virtualMachine
     */
    public function resetChangeBlockTracking(VirtualMachine $virtualMachine)
    {
        $this->setCbtEnabled($virtualMachine, false);
        $snapshot = $this->createSnapshot($virtualMachine);
        $this->removeSnapshot($snapshot);

        $this->setCbtEnabled($virtualMachine, true);
        $snapshot = $this->createSnapshot($virtualMachine);
        $this->removeSnapshot($snapshot);
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return string|null
     */
    public function getEsxHostVersion(VirtualMachine $virtualMachine)
    {
        return $virtualMachine->runtime->host->config->product->version;
    }

    /**
     * @param Vhost $virtualizationHost
     * @param DeviceLoggerInterface $logger
     */
    public function validateConnection(Vhost $virtualizationHost, DeviceLoggerInterface $logger)
    {
        $connectionType = $this->getConnectionType($virtualizationHost);
        $fullName = $this->getFullHostVersion($virtualizationHost);

        if ($connectionType === self::CONNECTION_TYPE_VCENTER) {
            $logger->info('VAC0001 Connecting to a vCenter', ['name' => $fullName]);
        } elseif ($connectionType === self::CONNECTION_TYPE_ESX) {
            $logger->info('VAC0002 Connecting to an ESX server', ['name' => $fullName]);
            $vCenterIpAddress = $this->getManagementServerIp($virtualizationHost);
            if (!empty($vCenterIpAddress)) {
                $logger->warning(
                    'VAC0003 Warning, connecting to an ESX host bypassing its vCenter server',
                    ['ip' => $vCenterIpAddress]
                );
                throw new BypassedVcenterException($virtualizationHost->host, $vCenterIpAddress);
            }
        } else {
            $msg = 'Warning, unrecognized vSphere API type';
            $logger->warning('VAC0004 ' . $msg, ['type' => $connectionType]);
            throw new RuntimeException($msg);
        }
    }

    /**
     * @param Vhost $virtualizationHost
     * @param string $uuid
     * @return null|VirtualMachine
     */
    public function findVmByBiosUuid(Vhost $virtualizationHost, string $uuid)
    {
        $vms = $this->findAllVmsByUuid($virtualizationHost, $uuid);

        if (count($vms) > 1) {
            throw new RuntimeException("Failed to uniquely identify VM by bios UUID.");
        }

        if (count($vms) === 0) {
            return null;
        }

        return $vms[0];
    }

    /**
     * @param Vhost $virtualizationHost
     * @param string $uuid
     * @return string
     */
    public function findVmMoRefIdByBiosUuid(Vhost $virtualizationHost, string $uuid)
    {
        /** @var VirtualMachine|null $vm */
        $vm = $this->findVmByBiosUuid($virtualizationHost, $uuid);

        if ($vm instanceof VirtualMachine) {
            return $vm->getReferenceId();
        }

        return '';
    }

    /**
     * BIOS SerialNumber is a string exposed by the HV straight to the VM.
     * It looks like: "VMware-42 18 1c 31 6d 80 7d 83-8b 4a 30 26 72 23 ad 01"
     *
     * From it we can easily retrieve the UUID the way that the vSphere API expects it.
     *
     * @param Vhost $virtualizationHost
     * @param string $biosSerialNumber
     * @return string
     */
    public function findVmMoRefIdByBiosSerialNumber(Vhost $virtualizationHost, string $biosSerialNumber)
    {
        /** @var VirtualMachine|null $vm */
        $vm = $this->findVmByBiosSerialNumber($virtualizationHost, $biosSerialNumber);

        if ($vm instanceof VirtualMachine) {
            return $vm->getReferenceId();
        }

        return '';
    }

    /**
     * @param Vhost $virtualizationHost
     * @param string $biosSerialNumber
     * @return VirtualMachine|null
     */
    public function findVmByBiosSerialNumber(Vhost $virtualizationHost, string $biosSerialNumber)
    {
        $id = trim($biosSerialNumber);

        if (strpos($id, 'VMware-') === false) {
            throw new Exception("Unexpected serial number format: " . $id);
        }

        $id = str_replace(['VMware-', '-', ' '], '', $id);
        $bytes = str_split($id, 4);

        # Target/Required identifier example: "421863c8-bd78-b264-0a74-016d9c764e8e"

        $uuid = sprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            $bytes[0],
            $bytes[1],
            $bytes[2],
            $bytes[3],
            $bytes[4],
            $bytes[5],
            $bytes[6],
            $bytes[7]
        );

        return $this->findVmByBiosUuid($virtualizationHost, $uuid);
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return bool
     */
    public function isVmRunningOnSnapshots(VirtualMachine $virtualMachine): bool
    {
        return $virtualMachine->hasSnapshots();
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return string[]
     */
    private function getVmVirtualDisksUuids(VirtualMachine $virtualMachine)
    {
        $diskUuids = [];
        $devices = $virtualMachine->config->hardware->device;

        foreach ($devices as $device) {
            if ($device instanceof \VirtualDisk) {
                $diskUuids[] = $device->backing->uuid;
            }
        }

        return $diskUuids;
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return \VirtualDisk[]
     */
    private function getVmVirtualDisks(VirtualMachine $virtualMachine): array
    {
        $virtualDisks = [];
        $devices = $virtualMachine->config->hardware->device;

        foreach ($devices as $device) {
            if ($device instanceof \VirtualDisk && $this->isVirtualDiskCompatible($device)) {
                $virtualDisks[] = $device;
            }
        }

        return $virtualDisks;
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @param VirtualDisk|VirtualDevice $virtualDisk
     */
    private function detachDiskFromVm(VirtualMachine $virtualMachine, $virtualDisk)
    {
        /** @var VirtualDevice|VirtualDisk $vDisk */
        $vDisk = new \VirtualDisk();
        $vDisk->capacityInKB = 0;
        $vDisk->key = $virtualDisk->key;
        $vDisk->controllerKey = $virtualDisk->controllerKey;

        $spec = new \VirtualMachineConfigSpec();
        $spec->deviceChange = new \VirtualDeviceConfigSpec(
            'remove',
            null,
            $vDisk
        );

        $task = $virtualMachine->ReconfigVm_Task([
            'spec' => $spec
        ]);

        if ($this->waitForTask($task) === false) {
            throw new RuntimeException("Failed detaching disk with key: {$vDisk->key}");
        }
    }

    /**
     * @param VirtualMachine $targetVm
     * @param VirtualMachine $sourceVm
     * @return string[]
     */
    public function findOrphanedDisksUuids(VirtualMachine $targetVm, VirtualMachine $sourceVm)
    {
        $targetVmDisksUuids = $this->getVmVirtualDisksUuids($targetVm);
        $sourceVmDisksUuids = $this->getVmVirtualDisksUuids($sourceVm);

        return array_intersect($targetVmDisksUuids, $sourceVmDisksUuids);
    }

    /**
     * Removes drives from $targetVm that are also drives of $sourceVm.
     *
     * @param VirtualMachine $targetVm
     * @param array $orphanDrivesUuids
     * @param DeviceLoggerInterface $logger
     */
    public function removeOrphanedDisks(VirtualMachine $targetVm, array $orphanDrivesUuids, DeviceLoggerInterface $logger)
    {
        $targetVmDisks = $this->getVmVirtualDisks($targetVm);

        foreach ($targetVmDisks as $targetVmDisk) {
            $targetVmDiskUuid = $targetVmDisk->backing->uuid;

            if (in_array($targetVmDiskUuid, $orphanDrivesUuids, true)) {
                $targetVmDiskFileName = $targetVmDisk->backing->fileName;
                $logger->warning('VAC0005 A drive was orphaned on VM, detaching...', ['diskName' => $targetVmDiskFileName]);
                try {
                    $this->detachDiskFromVm($targetVm, $targetVmDisk);
                    $logger->info('VAC0006 Drive successfully detached.');
                } catch (Throwable $exception) {
                    $logger->warning('VAC0007 Error detaching drive', ['exception' => $exception]);
                }
            }
        }
    }

    /**
     * @param Vhost $virtualizationHost
     * @param string $uuid
     * @return VirtualMachine[]
     */
    private function findAllVmsByUuid(Vhost $virtualizationHost, string $uuid)
    {
        try {
            $vms = $virtualizationHost->getServiceContent()->searchIndex->FindAllByUuid([
                'uuid' => $uuid,
                'vmSearch' => true
            ]);
        } catch (Exception $e) {
            return []; // Standalone esx hosts throw an exception when it can't find any VMs
        }

        return $vms;
    }

    /**
     * @param Vhost $virtualizationHost
     * @return string
     */
    public function getStandaloneEsxHostUuid(Vhost $virtualizationHost): string
    {
        $host = $virtualizationHost->findOneManagedObject(
            'HostSystem',
            'ha-host',
            ['hardware.systemInfo.uuid']
        );

        return $host->hardware->systemInfo->uuid ?? self::UNKNOWN_UUID;
    }

    /**
     * @param Vhost $virtualizationHost
     * @return string
     */
    public function getVcenterUuid(Vhost $virtualizationHost): string
    {
        return $virtualizationHost->getServiceContent()->about->instanceUuid ?? self::UNKNOWN_UUID;
    }

    /**
     * Get the uuid for the vcenter or esx host depending on which one we're connected to
     *
     * @param Vhost $virtualizationHost
     * @return string UUID
     */
    public function getUuid(Vhost $virtualizationHost): string
    {
        if ($this->getConnectionType($virtualizationHost) === self::CONNECTION_TYPE_VCENTER) {
            return $this->getVcenterUuid($virtualizationHost);
        }

        return $this->getStandaloneEsxHostUuid($virtualizationHost);
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return string
     */
    public function getVmBiosUuid(VirtualMachine $virtualMachine): string
    {
        return $virtualMachine->config->uuid;
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @return string
     */
    public function getVmInstanceUuid(VirtualMachine $virtualMachine): string
    {
        return $virtualMachine->config->instanceUuid;
    }

    /**
     * @param Vhost $virtualizationHost
     * @return mixed
     */
    public function getConnectionType(Vhost $virtualizationHost)
    {
        return $virtualizationHost->getApiType();
    }

    /**
     * @param Vhost $virtualizationHost
     * @return string
     */
    private function getFullHostVersion(Vhost $virtualizationHost): string
    {
        return $virtualizationHost->getServiceContent()->about->fullName ?? "";
    }

    /**
     * @param Vhost $virtualizationHost
     * @return string
     */
    private function getManagementServerIp(Vhost $virtualizationHost): string
    {
        // get hosts and pre-fetch the property
        $host = $virtualizationHost->findOneManagedObject(
            'HostSystem',
            'ha-host',
            ['summary.managementServerIp']
        );

        return $host->summary->managementServerIp ?? "";
    }

    /**
     * @param VirtualMachine $virtualMachine
     * @param string $snapshotMoRefId
     * @param string $driveKey
     * @param int $startOffset
     * @param string $changeId
     * @return mixed
     */
    public function queryChangedDiskAreas(
        VirtualMachine $virtualMachine,
        string $snapshotMoRefId,
        string $driveKey,
        int $startOffset,
        string $changeId
    ) {
        $changes = $virtualMachine->QueryChangedDiskAreas([
            'snapshot' => $snapshotMoRefId,
            'deviceKey' => $driveKey,
            'startOffset' => $startOffset,
            'changeId' => $changeId,
        ]);

        return $changes;
    }

    /**
     * @param ManagedObject $snapshot
     */
    public function removeSnapshot(ManagedObject $snapshot)
    {
        $removeTask = $snapshot->RemoveSnapshot_Task(['removeChildren' => false]);
        if ($this->waitForTask($removeTask) === false) {
            throw new Exception("Failed to remove snapshot.");
        }
    }

    /**
     * Returns a Snapshot managed object instance
     *
     * @param Vhost $virtualizationHost
     * @param string $moRefId
     * @return ManagedObject
     *  Returns snapshot instance that was created during prepareVixConnection.
     */
    public function retrieveSnapshot(Vhost $virtualizationHost, string $moRefId)
    {
        $snapshot = null;

        try {
            $snapshot = $virtualizationHost->findOneManagedObject(
                'VirtualMachineSnapshot',
                $moRefId,
                []
            );
        } catch (Exception $ex) {
            $this->logger->error('VAC0009 Vmware failed retrieving snapshot.', ['exception' => $ex]);
            $exceptionMessage = 'Vmware failed retrieving snapshot, exception thrown.';

            if (strpos($ex->getMessage(), 'Cannot complete login') !== false) {
                $exceptionMessage .= ", login error.";
            }

            throw new Exception("$exceptionMessage");
        }

        if (!$snapshot) {
            throw new Exception('Vmware failed retrieving snapshot.');
        }

        return $snapshot;
    }

    /**
     * Get remote paths to VMDK images that can be backed up.
     *
     * This basically filters our RDM drives that cannot be snapshotted to take
     * a backup.
     *
     * @param ManagedObject $snapshot
     * @return string[]
     */
    public function retrieveVmdkPaths(ManagedObject $snapshot)
    {
        $paths = [];
        $devices = $snapshot->config->hardware->device;

        foreach ($devices as $device) {
            if ($device instanceof VirtualDisk && $this->isVirtualDiskCompatible($device)) {
                $paths[] = $device->backing->fileName;
            }
        }

        return $paths;
    }

    /**
     * Uploads a file to an esx host
     *
     * @param EsxConnection $esxConnection Connection to the host you want to upload to
     * @param string $datastore The datastore you want the file to go to
     * @param string $uploadDir The directory within the datastore you want the file to go to
     * @param string $filePath The file to upload
     * @param callable $progressCallback Gets called once per second and receives one parameter, the total bytes
     *     uploaded so far. Return true from the callback if you want to abort the upload.
     */
    public function uploadFile(EsxConnection $esxConnection, string $datastore, string $uploadDir, string $filePath, callable $progressCallback)
    {
        $host = $esxConnection->getPrimaryHost();
        $path = urlencode($uploadDir . '/' . basename($filePath));
        $datacenter = rawurlencode($esxConnection->getDataCenter());
        $datastore = rawurlencode($datastore);
        $url = "https://$host/folder/$path?dcPath=$datacenter&dsName=$datastore";

        $fileSize = $this->filesystem->getSize($filePath);

        /**
         * A CURL callback handler to report current upload progress.
         * @param resource $a curlResource - Not used
         * @param int $b downBytesExpected - Not used
         * @param int $c downBytesComplete - Not used
         * @param int $d uploadBytesExpected Total bytes to upload - Not used
         * @param int $bytesUploaded Total bytes uploaded
         * @return int A non-zero return aborts upload/download
         */
        $updateProgress = function ($a, $b, $c, $d, $bytesUploaded) use ($progressCallback) {
            // This function gets called extremely fast. To save cpu usage only do work once per second.
            $currentTime = $this->dateTimeService->getTime();
            if ($this->time + 1 > $currentTime) {
                return 0;
            }

            if ($progressCallback($bytesUploaded)) {
                return 1; // non 0 val will make CURL abort transfer
            }

            $this->time = $currentTime;
        };

        $this->curlRequest->init($url)
            ->setOption(CURLOPT_UPLOAD, true)
            ->setOption(CURLOPT_NOSIGNAL, false)
            ->setOption(CURLOPT_SSL_VERIFYPEER, false)
            ->setOption(CURLOPT_SSL_VERIFYHOST, false)
            ->setOption(CURLOPT_FOLLOWLOCATION, false)
            ->setOption(CURLOPT_RETURNTRANSFER, true)
            ->setOption(CURLOPT_USERPWD, $esxConnection->getUser() . ':' . $esxConnection->getPassword())
            ->setOption(CURLOPT_INFILE, $this->filesystem->open($filePath, 'rb'))
            ->setOption(CURLOPT_INFILESIZE, $fileSize)
            ->setOption(CURLOPT_NOPROGRESS, false)
            ->setOption(CURLOPT_PROGRESSFUNCTION, $updateProgress);

        $this->curlRequest->execute();
        $this->curlRequest->close();
    }

    /**
     * @param VirtualDisk|ManagedObject $virtualDisk
     * @return bool
     */
    private function isVirtualDiskCompatible($virtualDisk)
    {
        $backingInfo = $virtualDisk->backing;

        if ($backingInfo instanceof VirtualDiskRawDiskMappingVer1BackingInfo) {
            return false;
        }

        return true;
    }

    /**
     * Waits for ESX task to complete.
     *
     * Some vSphere SOAP method calls are asynchronous and they return a task
     * object upon invocation which should  be used to track progress/state
     * of the launched task.
     *
     * @param mixed $task
     * @return mixed
     *  Task result on success, false if it failed. The reason for
     *  failure can be extracted from $task->info->error.
     */
    private function waitForTask($task)
    {
        while (true) {
            $info = $task->info;

            // this may happen if ESX user had insufficient permissions...
            if ($info === null) {
                return false;
            }

            if ($info->state === 'success') {
                return $info->result;
            }

            if ($info->state === 'error') {
                return false;
            }

            $this->sleep->sleep(2);
        }
    }
}
