<?php

namespace Datto\Virtualization;

use Datto\Config\Virtualization\VirtualDisk;
use Datto\Config\Virtualization\VirtualDisks;
use Datto\Connection\Libvirt\EsxConnection;
use Datto\Connection\Libvirt\EsxHostType;
use Datto\Core\Network\DeviceAddress;
use Datto\Filesystem\TransparentMount;
use Datto\Iscsi\IscsiTarget;
use Datto\Nfs\NfsExportManager;
use Datto\Common\Utility\Filesystem;
use Datto\Virtualization\Exceptions\EsxDatastoreDirectoryAddException;
use Datto\Virtualization\Exceptions\EsxDatastoreDirectoryRemoveException;
use Datto\Virtualization\Exceptions\EsxIscsiMissingLunException;
use Datto\Virtualization\Exceptions\EsxIscsiTargetException;
use Datto\Virtualization\Exceptions\EsxNfsDatastoreException;
use Datto\Virtualization\Exceptions\EsxRdmException;
use Datto\Log\DeviceLoggerInterface;
use Exception;
use Vmwarephp;
use Vmwarephp\Extensions\Datastore;

/**
 * Handles offload of virtual disks to ESX hosts.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Jason Lodice <jlodice@datto.com>
 */
class EsxRemoteStorage extends BaseRemoteStorage
{
    const VMDK_PATH_FORMAT = "[%s] %s/%s";

    private EsxConnection $connection;
    private DeviceAddress $deviceAddress;
    private NfsExportManager $nfsExportManager;
    private Filesystem $filesystem;
    private int $rdmRetryCount = 0;
    private TransparentMount $transparentMount;

    public function __construct(
        EsxConnection $connection,
        DeviceAddress $deviceAddress,
        Filesystem $filesystem,
        IscsiTarget $iscsiTarget,
        NfsExportManager $nfsExportManager,
        TransparentMount $transparentMount,
        DeviceLoggerInterface $logger
    ) {
        parent::__construct($iscsiTarget, $logger);
        $this->connection = $connection;
        $this->deviceAddress = $deviceAddress;
        $this->nfsExportManager = $nfsExportManager;
        $this->filesystem = $filesystem ;
        $this->transparentMount = $transparentMount;
    }

    /**
     * Share VM disk image to ESX host for virtualization.
     *
     * Uses either iSCSI or NFS offload method. If iSCSI fails, it uses
     * NFS as fallback, then if that fails too, exception is thrown.
     *
     * @param string $vmName
     * @param string $storageDir
     * @param bool $isEncrypted
     * @param VirtualDisks $disks
     *
     * @return VirtualDisks the array of VirtualDisk as "seen" in ESX host.
     */
    public function offload(string $vmName, string $storageDir, bool $isEncrypted, VirtualDisks $disks): VirtualDisks
    {
        /** @var VirtualDisk $disk */
        foreach ($disks as $disk) {
            if ($disk->getStorageLocation() !== $storageDir) {
                throw new \RuntimeException("Expected source disks to all exist in '$storageDir'");
            }
        }

        if ($this->connection->getOffloadMethod() == EsxConnection::OFFLOAD_NFS) {
            $remoteDisks = $this->offloadNfs($vmName, $storageDir, $isEncrypted, $disks);
        } else {
            try {
                $remoteDisks = $this->offloadIscsi($vmName, $storageDir, $disks);
            } catch (\Exception $ex) {
                $this->logger->error('ESX0200 Failed iSCSI offload. Falling back to NFS.', ['exception' => $ex]);

                $this->tearDownIscsi($vmName, $storageDir);

                $remoteDisks = $this->offloadNfs($vmName, $storageDir, $isEncrypted, $disks);

                // change to NFS by default.
                $this->connection->setOffloadMethod(EsxConnection::OFFLOAD_NFS);
                $this->connection->saveData();
            }
        }

        return $remoteDisks;
    }

    /**
     * Exposes VM *.datto files over iSCSI to ESX host.
     *
     * @param string $vmName
     * @param string $storageDir
     * @param VirtualDisks $localDisks
     *
     * @return VirtualDisks
     */
    private function offloadIscsi(string $vmName, string $storageDir, VirtualDisks $localDisks): VirtualDisks
    {
        $datastore = $this->connection->getDatastore();
        $uniqueName = basename($storageDir);

        $targetName = $this->createLocalIscsiTarget($uniqueName, $localDisks);
        $this->logger->debug("ESX0201 Adding iSCSI target for \"$vmName\"");

        $this->addHostIscsiTarget($targetName);

        $directoryCreated = $this->addHostDatastoreDirectory($datastore, $vmName);

        $remoteDisks = new VirtualDisks();
        foreach ($localDisks as $drive) {
            $vmdk = $drive->getVmdkFileName();
            if ($directoryCreated) {
                $sn = $this->makeDiskSerialNumber($uniqueName, $vmdk);
                $rawDevice = $this->getHostDiskId($sn);
                $vmdkPath = $this->addHostRawDeviceMappedFile($rawDevice, $datastore, $vmName, $vmdk);
            } else {
                $vmdkPath = $this->getVmdkPath($datastore, $vmName, $vmdk);
            }

            $remoteDisks->append(new VirtualDisk(
                $drive->getRawFileName(),
                $drive->getVmdkFileName(),
                dirname($vmdkPath),
                $drive->isGpt()
            ));
        }

        $this->logger->debug("ESX0205 ISCSI target for \"$vmName\" was added successfully.");

        return $remoteDisks;
    }

    /**
     * Exposes VM *.datto files over NFS to ESX host.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $vmName
     * @param string $storageDir
     * @param bool $isEncrypted
     * @param VirtualDisks $localDisks
     *
     * @return VirtualDisks
     */
    private function offloadNfs(
        string $vmName,
        string $storageDir,
        bool $isEncrypted,
        VirtualDisks $localDisks
    ): VirtualDisks {
        $esxApi = $this->connection->getEsxApi();
        $nfsServerIp = $this->getSelfIp();
        $nfsShareDir = $isEncrypted ? $this->transparentMount->getTransparentPath($storageDir) : $storageDir;

        $this->logger->debug("ESX0210 Attaching NFS datastore for \"$vmName\"");

        // ESX uses NFS shares.  NFS cannot expose symlinked files.
        // Transparent mount works around this limitation, but the directory will change
        if ($isEncrypted && !$this->transparentMount->hasTransparentMount($storageDir)) {
            $this->transparentMount->createTransparentMount($storageDir);
            $this->logger->debug("ESX0213 Creating transparent mount from '$storageDir' to '$nfsShareDir'");
        }

        // our nfs export configuration uses 'all_squash' which maps uid on hypervisor to 'nobody' (uid 65534)
        // therefore, the clone dir must have explicit read/write for other so that hypervisor can read/write the share
        // (Note, permissions are set on the underlying storageDir, NOT the transmnt dir)
        $this->filesystem->chmod($storageDir, 0777);

        $this->nfsExportManager->enable($nfsShareDir, ['host' => $this->connection->getEsxHost()]);

        $datastoreName = $vmName;
        $spec = new \HostNasVolumeSpec();
        $spec->accessMode = 'readWrite';
        $spec->localPath = $datastoreName;
        $spec->remoteHost = $nfsServerIp;
        $spec->remotePath = $nfsShareDir;
        $spec->type = 'nfs';

        $params = [
            'spec' => $spec,
        ];

        try {
            $esxApi->getDatastoreSystem()->createNasDatastore($params);
        } catch (Vmwarephp\Exception\Soap $ex) {
            $msg = $ex->getMessage();
            $byMount = false;

            $duplicateNamePosition = strpos($msg, 'DuplicateName');
            $alreadyExistsPosition = strpos($msg, 'AlreadyExists');

            if (false !== $duplicateNamePosition || false !== ($byMount = $alreadyExistsPosition)) {
                $this->logger->debug(sprintf(
                    'ESX0215 NFS datastore named %s already exists and will be replaced.',
                    $datastoreName
                ));

                // if error was DuplicateName, we can simply lookup by name
                if (false === $byMount) {
                    $datastore = $esxApi->getVhost()->findManagedObjectByName(
                        'Datastore',
                        $datastoreName,
                        []
                    );
                } else {
                    // for the stupid AlreadyExists, we can't search by name as
                    // it may be different (e.g. timestamp suffix or whatever
                    // the reason is for AlreadyExists rather than DuplicateName
                    // error message)
                    $ret = preg_match('/\[name\] => (?<hostMount>.*)/', $msg, $matches);

                    // no way to lookup the "duplicate" datastore, give up.
                    if (!$ret) {
                        throw new EsxNfsDatastoreException();
                    }

                    $datastore = $this->findNfsDatastore($matches['hostMount']);
                }

                if ($datastore) {
                    $this->recreateDatastore($datastore, $params);
                }
            } else {
                throw new EsxNfsDatastoreException($ex);
            }
        }

        // create a new VirtualDisks collection adjusted with remote path.
        $remoteDisks = new VirtualDisks();
        foreach ($localDisks as $drive) {
            $backingFilePath = $drive->getStorageLocation() . '/' . $drive->getRawFileName();
            $this->filesystem->chmod($backingFilePath, 0666);

            $remoteDisks->append(new VirtualDisk(
                $drive->getRawFileName(),
                $drive->getVmdkFileName(),
                sprintf('[%s] ', $datastoreName),
                $drive->isGpt()
            ));
        }

        $this->logger->debug("ESX0219 NFS datastore for \"$vmName\" attached successfully.");

        return $remoteDisks;
    }

    /**
     * @inheritdoc
     */
    public function tearDown(string $vmName, string $storageDir, bool $isEncrypted)
    {
        if ($this->connection->getOffloadMethod() == EsxConnection::OFFLOAD_NFS) {
            $this->tearDownNfs($vmName, $storageDir, $isEncrypted);
        } else {
            $this->tearDownIscsi($vmName, $storageDir);
        }
    }

    /**
     * Find an existing NFS datastore by hostmount. Returns false if it cannot be found
     *
     * @param string $hostMount
     * @return Datastore|bool
     */
    private function findNfsDatastore(string $hostMount)
    {
        $esxApi = $this->connection->getEsxApi();
        $nfsServerIp = $this->getSelfIp();

        $allDatastores = $esxApi->getVhost()->findAllManagedObjects(
            'Datastore',
            // pre-fetch the properties we're interested in
            ['host', 'info', 'summary.type']
        );

        $datastore = false;

        foreach ($allDatastores as $anyDs) {
            // only care for NFS datastores
            if ($anyDs->summary->type !== 'NFS') {
                continue;
            }

            // double-check if it truly points at us...
            if ($anyDs->info->nas->remoteHost !== $nfsServerIp) {
                continue;
            }

            // inspect hostMountInfo to match the mount path.
            foreach ($anyDs->host as $dsHostMount) {
                if ($dsHostMount->mountInfo->path === $hostMount) {
                    $datastore  = $anyDs;
                    break;
                }
            }

            // already found, abort loop
            if ($datastore) {
                break;
            }
        }

        return $datastore;
    }

    /**
     * Remove the existing Datastore and recreate using the parameters
     *
     * @psalm-suppress UndefinedClass
     *
     * @param $existingDatastore
     * @param array $params
     */
    private function recreateDatastore($existingDatastore, array $params)
    {
        $esxApi = $this->connection->getEsxApi();

        $removeParams = array(
            'datastore' => $existingDatastore->toReference(),
        );

        $esxApi->getDatastoreSystem()->RemoveDatastore($removeParams);

        try {
            $esxApi->getDatastoreSystem()->createNasDatastore($params);
        } catch (Vmwarephp\Exception\Soap $ex) {
            $this->logger->error('ESX0220 Failed to re-create NFS Datastore', ['exception' => $ex]);
            throw new EsxNfsDatastoreException($ex);
        }
    }

    /**
     * Cleans up any iSCSI related stuff on ESX host and local device.
     *
     * @param string $vmName
     * @param string $storageDir
     */
    private function tearDownIscsi(string $vmName, string $storageDir)
    {
        // cleanup ESX datastore
        $datastore = $this->connection->getDatastore();
        $this->removeHostDatastoreDirectory($datastore, $vmName);

        $uniqueName = basename($storageDir);
        $existingTarget = $this->iscsiTarget->makeTargetName($uniqueName);

        $this->logger->debug("ESX0230 Removing iSCSI target for \"$vmName\"");

        try {
            $this->removeHostIscsiTarget($existingTarget);
        } catch (\Exception $ex) {
            $this->logger->error('ESX0233 Failed to remove iSCSI target from ESX - continuing removal on device.', [
                'existingTarget' => $existingTarget,
                'esxHost' => $this->connection->getEsxHost()
            ]);
        }

        if ($this->iscsiTarget->doesTargetExist($existingTarget)) {
            $this->destroyLocalIscsiTarget($existingTarget);
        } else {
            $this->logger->warning('ESX0235 iSCSI target not found - datastore files removed.');
        }

        $this->logger->debug("ESX0239 iSCSI target \"$vmName\" removed successfully.");
    }

    /**
     * Cleanup NFS related things on ESX host and local device.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $vmName
     * @param string $storageDir
     * @param bool $isEncrypted
     */
    private function tearDownNfs(string $vmName, string $storageDir, bool $isEncrypted)
    {
        if (empty($storageDir)) {
            throw new \InvalidArgumentException(
                'Expected storageDir to be non-empty'
            );
        }

        $this->logger->debug("ESX0240 Removing NFS datastore for \"$vmName\"");

        $esxApi = $this->connection->getEsxApi();
        $datastoreName = $vmName;

        $datastore = $esxApi->getVhost()->findManagedObjectByName(
            'Datastore',
            $datastoreName,
            []
        );

        if ($datastore) {
            $params = [
                'datastore' => $datastore->toReference(),
            ];

            try {
                $esxApi->getDatastoreSystem()->RemoveDatastore($params);
            } catch (\Exception $ex) {
                // all we can do is to log the issue, the remaning code
                // still must run to cleanup things on device side.
                $this->logger->error('ESX0243 Failed to remove NFS datastore from ESX host', ['exception' => $ex]);
            }
        }

        $hasTransparentMount = $isEncrypted && $this->transparentMount->hasTransparentMount($storageDir);
        $nfsShareDir = $hasTransparentMount ? $this->transparentMount->getTransparentPath($storageDir) : $storageDir;

        $this->logger->debug("ESX0245 Removing vm '$vmName' NFS share for '$nfsShareDir'");
        $this->nfsExportManager->disable($nfsShareDir);

        if ($hasTransparentMount) {
            $this->logger->debug("ESX0247 Removing transparent mount from '$storageDir' to '$nfsShareDir'");
            $this->transparentMount->removeTransparentMount($nfsShareDir);
        }

        $this->logger->debug("ESX0249 NFS datastore for \"$vmName\" removed succesfully.");
    }

    /**
     * Adds the iSCSI target to ESX's static discovery list.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $targetName
     */
    private function addHostIscsiTarget($targetName)
    {
        $esxApi = $this->connection->getEsxApi();

        try {
            $scsiHba = $this->connection->getIscsiHba();
            $ip = $this->getSelfIp();

            $esxApi->getStorageSystem()->AddInternetScsiStaticTargets(
                [
                    'iScsiHbaDevice' => $scsiHba,
                    'targets' => [
                        'iScsiName' => $targetName,
                        'address' => $ip,
                        'port' => 3260,
                    ]
                ]
            );

            $esxApi->getStorageSystem()->RescanHba(array('hbaDevice' => $scsiHba));
        } catch (\Exception $ex) {
            // if target already exists, move on else throw error.
            if (stripos($ex->getMessage(), 'already exists') === false) {
                throw new EsxIscsiTargetException($ex);
            }
        }
    }

    /**
     * Removes the iSCSI target from ESX's static discovery list.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $targetName
     */
    private function removeHostIscsiTarget(string $targetName)
    {
        $esxApi = $this->connection->getEsxApi();

        try {
            $ip = $this->getSelfIp();
            $esxApi->getStorageSystem()->RemoveInternetScsiStaticTargets(
                [
                    'iScsiHbaDevice' => $this->connection->getIscsiHba(),
                    'targets' => [
                        'iScsiName' => $targetName,
                        'address' => $ip,
                        'port' => 3260,
                    ]
                ]
            );
        } catch (\Exception $ex) {
            // if it's already gone, move on..
            if (strpos($ex->getMessage(), 'No such StaticTarget') === false) {
                throw new EsxIscsiTargetException($ex);
            }
        }
    }

    /**
     * Get the device's IP address to use for configuring NFS/iSCSI
     *
     * @return string IP address
     */
    private function getSelfIp(): string
    {
        $ip = $this->deviceAddress->getLocalIp($this->connection->getEsxHost());
        if (!$ip) {
            throw new Exception('Could not determine device IP for ESX offload');
        }
        return $ip;
    }

    /**
     * Get LUN path on the host by matching by SCSI serial number.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $scsiSn
     *
     * @return string
     */
    private function getHostDiskId($scsiSn): string
    {
        $esxApi = $this->connection->getEsxApi();
        $storageDevice = $esxApi->getHostSystem()->config->storageDevice;

        foreach ($storageDevice->scsiLun as $lun) {
            // $lun->alternateName may not exist for all LUNs but it may
            // exist for others, so don't fail out if it doesn't exist
            try {
                foreach ($lun->alternateName as $alternateName) {
                    if ($alternateName->namespace === 'SERIALNUM') {
                        $esxLunSerialNumber = trim(implode(array_map('chr', $alternateName->data)));

                        if ($esxLunSerialNumber === $scsiSn) {
                            return trim($lun->devicePath);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // This may occur if the optional "alternateName" property
                // doesn't exist, so continue to the next device
            }
        }

        $this->logger->error('ESX0250 Could not find iSCSI LUN', ['scsiSn' => $scsiSn]);
        throw new EsxIscsiMissingLunException();
    }

    /**
     * Gets the formatted vmdk path
     *
     * @param string $datastore
     * @param string $dirName
     * @param string $vmdkFile
     * @return string
     */
    private function getVmdkPath(string $datastore, string $dirName, string $vmdkFile): string
    {
        $vmdkPath = sprintf(
            self::VMDK_PATH_FORMAT,
            $datastore,
            $dirName,
            $vmdkFile
        );

        return $vmdkPath;
    }

    /**
     * Creates a directory an a datastore.
     *
     * The directory is used to hold agent files like vmx, nvram, and vmdk file
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $datastore
     *  A datastore name without square brackets.
     * @param string $dirName
     * @return bool True if directory was created. False if it already existed
     */
    public function addHostDatastoreDirectory(string $datastore, string $dirName): bool
    {
        $esxApi = $this->connection->getEsxApi();

        try {
            $params = array(
                'name' => sprintf(
                    "[%s] %s",
                    $datastore,
                    $dirName
                ),
            );

            $this->addHostDatacenterParam($params);

            $esxApi->getServiceContent()->fileManager->MakeDirectory($params);
            return true;
        } catch (Vmwarephp\Exception\Soap $ex) {
            $msg = $ex->getMessage();

            // re-throw anything other than FileAlreadyExists, else just log.
            if (strpos($msg, 'FileAlreadyExists') !== false) {
                $this->logger->warning('ESX0253 Directory on datastore exists. Re-using.', [
                    'dirName' => $dirName,
                    'datastore' => $datastore
                ]);
                return false;
            }

            throw new EsxDatastoreDirectoryAddException($ex);
        }
    }

    /**
     * Removes a directory from datastore (recursive).
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $datastore
     *  A datastore name without square brackets.
     * @param string $dirName
     */
    public function removeHostDatastoreDirectory(string $datastore, string $dirName)
    {
        $esxApi = $this->connection->getEsxApi();

        // delete the directory on datastore that was created.
        $params = array(
            'name' => sprintf(
                '[%s] %s',
                $datastore,
                $dirName
            ),
        );

        $this->addHostDatacenterParam($params);

        $fileManager = $esxApi->getServiceContent()->fileManager;
        $task = $fileManager->DeleteDatastoreFile_Task($params);

        // if delete failed, check why and take action accordingly
        if (false === $this->waitForHostTask($task)) {
            $error = $task->info->error;

            // FileNotFound is not a critical failure so just log it.
            if ($error->fault instanceof \FileNotFound) {
                $this->logger->warning('ESX0255 Tried to delete non-existing folder on datastore', [
                    'dirName' => $dirName,
                    'datastore' => $datastore
                ]);
            } else {
                throw new EsxDatastoreDirectoryRemoveException($error->localizedMessage);
            }
        }
    }

    /**
     * Creates a Raw Device Mapped VMDK file on a datastore, or just returns the vmdk path if it already exists.
     *
     * @psalm-suppress UndefinedClass
     *
     * @param string $rawDevice
     *  iSCSI LUN raw device name.
     * @param string $datastore
     *  A datastore name without square brackets.
     * @param $dirName
     * @param $vmdkFile
     * @return string
     *  Path to the VMDK file.
     */
    private function addHostRawDeviceMappedFile($rawDevice, $datastore, $dirName, $vmdkFile): string
    {
        $esxApi = $this->connection->getEsxApi();

        $vmdkPath = $this->getVmdkPath($datastore, $dirName, $vmdkFile);

        if (!preg_match('/^\/vmfs\/devices\/disks\//', $rawDevice)) {
            $rawDevice = '/vmfs/devices/disks/' . $rawDevice;
        }

        $spec = new \DeviceBackedVirtualDiskSpec();
        $spec->device = trim($rawDevice);
        $spec->diskType = 'rdm';
        $spec->adapterType = 'busLogic';

        $params = array(
            'name' => $vmdkPath,
            'spec' => $spec,
        );

        $this->addHostDatacenterParam($params);

        $task = $esxApi->getServiceContent()->virtualDiskManager->CreateVirtualDisk_Task($params);

        if (false === $this->waitForHostTask($task)) {
            if ($this->rdmRetryCount >= 3) {
                $this->rdmRetryCount = 0;
                throw new EsxRdmException();
            } else {
                // wait for 2s more on each attempt to make sure, ESX gets
                // it's things straight - whatever that was.
                sleep($this->rdmRetryCount + 2);
                $this->rdmRetryCount++;

                $this->logger->warning('ESX0259 iSCSI RDM mapping failed', ['retry' => $this->rdmRetryCount]);

                $this->addHostRawDeviceMappedFile($rawDevice, $datastore, $dirName, $vmdkFile);
            }
        }

        $this->rdmRetryCount = 0;

        return $vmdkPath;
    }

    /**
     * Waits for ESX task to complete.
     *
     * Some vSphere SOAP method calls are asynchronous and they return a task
     * object upon invokation which should  be used to trask progress/state
     * of the launched task.
     *
     * @param object $task
     * @return bool
     *  True if task complete successful, false if it failed. The reason for
     *  failure can be extracted from $task->info->error.
     */
    private function waitForHostTask($task): bool
    {
        while (true) {
            $info = $task->info;

            if (null === $info) {
                $this->logger->error('ESX0260 Failed to obtain task state. Check ESX user permissions.', [
                    'task' => $task->toReference()->_
                ]);

                return false;
            }

            if ($info->state == 'success') {
                return true;
            } elseif ($info->state == 'error') {
                return false;
            }

            sleep(2);
        }
    }

    /**
     * Adds datacenter name as a param to pass to Vmware API, if needed.
     *
     * @param array $params
     *  A refrence to array with params where datacenter name might be needed.
     *
     * @return void
     */
    private function addHostDatacenterParam(array &$params)
    {
        if ($this->connection->getHostType() != EsxHostType::STANDALONE) {
            $esxApi = $this->connection->getEsxApi();
            $ret = $esxApi->getVhost()->findManagedObjectByName(
                'Datacenter',
                $this->connection->getDataCenter(),
                []
            );
            $params['datacenter'] = $ret->toReference();
        }
    }
}
