<?php

namespace Datto\Restore\EsxUpload;

use Datto\Asset\Agent\AgentService;
use Datto\Config\AgentShmConfigFactory;
use Datto\Connection\Service\EsxConnectionService;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Resource\DateTimeService;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\PosixHelper;
use Datto\Common\Resource\Sleep;
use Datto\Restore\AgentHirFactory;
use Datto\Restore\AssetCloneManager;
use Datto\Restore\CloneSpec;
use Datto\Restore\RestoreService;
use Datto\Restore\RestoreType;
use Datto\Virtualization\VmwareApiClient;
use Datto\Virtualization\EsxRemoteStorage;
use Datto\Virtualization\RemoteHypervisorStorageFactory;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Manages the ESX Upload process.
 *
 * @author Dawid Zamirski <dzamirski@datto.com>
 * @author Matt Cheman <mcheman@datto.com>
 */
class EsxUploadManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const RESTORE_SUFFIX = 'esx-upload';
    const CANCEL_WAIT_TIMEOUT_SEC = 15;

    /** @var Filesystem */
    private $filesystem;

    /** @var Collector */
    private $collector;

    /** @var AgentService */
    private $agentService;

    /** @var AgentShmConfigFactory */
    private $agentShmConfigFactory;

    /** @var RestoreService */
    private $restoreService;

    /** @var AssetCloneManager */
    private $assetCloneManager;

    /** @var EsxConnectionService */
    private $esxConnectionService;

    /** @var DateTimeService */
    private $dateTimeService;

    /** @var Sleep */
    private $sleep;

    /** @var RemoteHypervisorStorageFactory */
    private $remoteHypervisorStorageFactory;
    
    /** @var PosixHelper */
    private $posixHelper;

    /** @var VmwareApiClient */
    private $vmwareApiClient;

    /** @var AgentHirFactory */
    private $agentHirFactory;

    public function __construct(
        Filesystem $filesystem,
        Collector $collector,
        AgentService $agentService,
        AgentShmConfigFactory $agentShmConfigFactory,
        RestoreService $restoreService,
        AssetCloneManager $assetCloneManager,
        EsxConnectionService $esxConnectionService,
        DateTimeService $dateTimeService,
        Sleep $sleep,
        RemoteHypervisorStorageFactory $remoteHypervisorStorageFactory,
        PosixHelper $posixHelper,
        VmwareApiClient $vmwareApiClient,
        AgentHirFactory $agentHirFactory
    ) {
        $this->filesystem = $filesystem;
        $this->collector = $collector;
        $this->agentService = $agentService;
        $this->agentShmConfigFactory = $agentShmConfigFactory;
        $this->restoreService = $restoreService;
        $this->assetCloneManager = $assetCloneManager;
        $this->esxConnectionService = $esxConnectionService;
        $this->dateTimeService = $dateTimeService;
        $this->sleep = $sleep;
        $this->remoteHypervisorStorageFactory = $remoteHypervisorStorageFactory;
        $this->posixHelper = $posixHelper;
        $this->vmwareApiClient = $vmwareApiClient;
        $this->agentHirFactory = $agentHirFactory;
    }

    /**
     * @param string $agentKey
     * @param int $snapshot
     * @param string $connectionName
     * @param string $datastore
     * @param string $uploadDir
     */
    public function doUpload(string $agentKey, int $snapshot, string $connectionName, string $datastore, string $uploadDir)
    {
        $this->logger->setAssetContext($agentKey);
        $this->logger->info(
            'ESX0001 Starting esx upload',
            ['snapshot' => $snapshot, 'datastore' => $datastore, 'uploadDir' => $uploadDir, 'connectionName' => $connectionName]
        );

        try {
            $shmConfig = $this->agentShmConfigFactory->create($agentKey);
            $esxUploadStatus = new EsxUploadStatus($snapshot);
            $esxUploadStatus->setStatus(EsxUploadStatus::STATUS_STARTING);
            $esxUploadStatus->setDatastore($datastore);
            $esxUploadStatus->setDirectory($uploadDir);
            $esxUploadStatus->setPid($this->posixHelper->getCurrentProcessId());
            $esxUploadStatus->setConnectionName($connectionName);
            $shmConfig->saveRecord($esxUploadStatus);

            $esxConnection = $this->esxConnectionService->get($connectionName);
            if ($esxConnection === null) {
                throw new Exception("Connection '$connectionName' does not exist. Can't continue.");
            }

            $agent = $this->agentService->get($agentKey);
            $this->collector->increment(Metrics::RESTORE_STARTED, [
                'type' => Metrics::RESTORE_TYPE_ESX_UPLOAD,
                'is_replicated' => $agent->getOriginDevice()->isReplicated()
            ]);

            $this->clearCancel($agentKey, $snapshot);
            $this->createRestore($agentKey, $snapshot, $connectionName);

            $esxUploadStatus->setStatus(EsxUploadStatus::STATUS_CLONE);
            $shmConfig->saveRecord($esxUploadStatus);

            $cloneSpec = CloneSpec::fromAgentAttributes($agentKey, $snapshot, RestoreType::ESX_UPLOAD);
            $this->assetCloneManager->createClone($cloneSpec);

            $imagesToUpload = $this->getFilesToUpload($cloneSpec);

            $this->logger->debug('ESX0003 Files to upload to esx:', $imagesToUpload);
            $vmdks = array_map(function ($image) {
                return basename($image['vmdk']['path']);
            }, $imagesToUpload);
            $esxUploadStatus->setVmdks($vmdks);

            $this->logger->debug('ESX0004 Creating directory on esx if necessary.');
            $esxUploadStatus->setStatus(EsxUploadStatus::STATUS_DATASTORE);
            $shmConfig->saveRecord($esxUploadStatus);

            /** @var EsxRemoteStorage $remoteStorage */
            $remoteStorage = $this->remoteHypervisorStorageFactory->create($esxConnection, $this->logger);
            $weCreatedDirectory = $remoteStorage->addHostDatastoreDirectory($datastore, $uploadDir);
            $esxUploadStatus->setCreatedDirectory($weCreatedDirectory);
            $shmConfig->saveRecord($esxUploadStatus);

            $esxUploadStatus->setStatus(EsxUploadStatus::STATUS_UPLOADING);
            foreach ($imagesToUpload as $imageInfo) {
                if ($this->isCancelled($agentKey, $snapshot)) {
                    break;
                }

                $esxUploadStatus->setTotalSize($imageInfo['vmdk']['size'] + $imageInfo['datto']['size']);
                $esxUploadStatus->setCurrentVmdk(basename($imageInfo['vmdk']['path']));
                $shmConfig->saveRecord($esxUploadStatus);

                foreach ($imageInfo as $fileInfo) {
                    if ($this->isCancelled($agentKey, $snapshot)) {
                        break;
                    }

                    $progress = function ($bytesUploaded) use ($esxUploadStatus, $shmConfig) {
                        if ($this->isCancelled($shmConfig->getAgentKey(), $esxUploadStatus->getSnapshot())) {
                            return true; // abort transfer
                        }

                        $esxUploadStatus->setUploadedSize($bytesUploaded);
                        $shmConfig->saveRecord($esxUploadStatus);
                        return false; // continue transfer
                    };

                    $filePath = $fileInfo['path'];
                    $this->logger->debug("ESX0005 Uploading $filePath");
                    $this->vmwareApiClient->uploadFile($esxConnection, $datastore, $uploadDir, $filePath, $progress);
                }
            }

            if (!$this->isCancelled($agentKey, $snapshot)) {
                $esxUploadStatus->setFinished(true);
                $shmConfig->saveRecord($esxUploadStatus);
                $this->logger->info('ESX0007 Successfully uploaded image to esx', ['agentKey' => $agentKey, 'connectionName' => $connectionName]);
            }
        } catch (Throwable $e) {
            $this->logger->error('ESX0002 Error during esx upload', ['exception' => $e]);
            $esxUploadStatus->setError($e->getMessage());
            $shmConfig->saveRecord($esxUploadStatus);
            throw $e;
        } finally {
            $this->logger->debug('ESX0008 Waiting 2 seconds to allow the UI to get the final esx upload status');
            $this->sleep->sleep(2);
            $this->cleanup($agentKey, $snapshot);
        }
    }

    /**
     * Cleanup esx upload
     *
     * @param string $agentKey
     * @param int $snapshot
     */
    public function cleanup(string $agentKey, int $snapshot)
    {
        $this->logger->setAssetContext($agentKey);
        $this->logger->debug('ESX0006 Cleaning up');

        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        $esxUploadStatus = new EsxUploadStatus($snapshot);
        $shmConfig->loadRecord($esxUploadStatus);

        if ($esxUploadStatus->createdDirectory() && !$esxUploadStatus->isFinished()) {
            $this->logger->debug('ESX0009 Deleting directory because we created it and the upload didn\'t complete.');
            try {
                $esxConnection = $this->esxConnectionService->get($esxUploadStatus->getConnectionName());
                /** @var EsxRemoteStorage $remoteStorage */
                $remoteStorage = $this->remoteHypervisorStorageFactory->create($esxConnection, $this->logger);
                $remoteStorage->removeHostDatastoreDirectory($esxUploadStatus->getDatastore(), $esxUploadStatus->getDirectory());
            } catch (Throwable $e) {
                $this->logger->error('ESX0010 Failed to delete directory', ['exception' => $e]);
            }
        }

        $cloneSpec = CloneSpec::fromAgentAttributes($agentKey, $snapshot, RestoreType::ESX_UPLOAD);
        if ($this->assetCloneManager->exists($cloneSpec)) {
            $this->assetCloneManager->destroyClone($cloneSpec);
        }

        // Handle removal of esx upload clones from the previous implementation, if present
        $cloneSpec = CloneSpec::fromAgentAttributes($agentKey, $snapshot, 'esxUpload');
        if ($this->assetCloneManager->exists($cloneSpec)) {
            $this->assetCloneManager->destroyClone($cloneSpec);
        }

        $this->clearCancel($agentKey, $snapshot);
        $this->removeRestore($agentKey, $snapshot);
        $shmConfig->clearRecord($esxUploadStatus);
        $this->logger->debug('ESX0011 Successfully cleaned up Esx Upload');
    }

    /**
     * Cancel the upload for the asset
     *
     * @param string $agentKey
     * @param int $snapshot
     * @param int $timeout Maximum time to wait after signaling the upload to cancel before killing it
     */
    public function cancel(string $agentKey, int $snapshot, int $timeout = self::CANCEL_WAIT_TIMEOUT_SEC)
    {
        $this->logger->setAssetContext($agentKey);
        $this->logger->info('ESX0008 Cancelling esx upload', ['snapshot' => $snapshot]);
        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        $isSuccessful = false;

        $shmConfig->touch("$snapshot.esxUploadCancel");

        $waitUntil = $this->dateTimeService->getTime() + $timeout;
        while ($this->dateTimeService->getTime() < $waitUntil) {
            // The cancel flag file is removed once the upload has been cleaned up. Wait until that happens
            if (!$this->isCancelled($agentKey, $snapshot)) {
                $isSuccessful = true;
                break;
            }
            $this->sleep->usleep(100 * 1000); // 100 ms
        }

        if (!$isSuccessful) {
            $this->logger->debug("ESX0008 Couldn't cancel gracefully. Killing esx upload.", ['snapshot' => $snapshot]);
            $esxUploadStatus = new EsxUploadStatus($snapshot);
            $shmConfig->loadRecord($esxUploadStatus);

            $isRunning =
                $esxUploadStatus->getPid() > 0 &&
                $this->posixHelper->isProcessRunning($esxUploadStatus->getPid());

            if ($isRunning) {
                $isRunning = !$this->posixHelper->kill($esxUploadStatus->getPid(), PosixHelper::SIGNAL_TERM);
            }

            if (!$isRunning) {
                $this->cleanup($agentKey, $snapshot);
                $isSuccessful = true;
            }
        }

        if ($isSuccessful) {
            $this->logger->info('ESX0012 ESX Upload cancelled successfully.');
        } else {
            throw new Exception('Timed out waiting for the esx upload to cancel');
        }
    }

    /**
     * Check if the upload is cancelled
     * @param string $agentKey
     * @param int $snapshot
     * @return bool True if the user wants to cancel the upload, otherwise false
     */
    public function isCancelled(string $agentKey, int $snapshot): bool
    {
        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        return $shmConfig->has("$snapshot.esxUploadCancel");
    }

    /**
     * Clear the cancel flag
     * @param string $agentKey
     * @param int $snapshot
     */
    public function clearCancel(string $agentKey, int $snapshot)
    {
        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        $shmConfig->clear("$snapshot.esxUploadCancel");
    }

    /**
     * Get the ESX upload progress data.
     *
     * @param string $agentKey
     * @param int $snapshot
     *
     * @return array
     */
    public function getProgress(string $agentKey, int $snapshot)
    {
        $shmConfig = $this->agentShmConfigFactory->create($agentKey);
        $esxUploadStatus = new EsxUploadStatus($snapshot);
        $shmConfig->loadRecord($esxUploadStatus);

        $isRunning = $esxUploadStatus->getPid() > 0 && $this->posixHelper->isProcessRunning($esxUploadStatus->getPid());

        return [
            'isRunning' => $isRunning,
            'isComplete' => $esxUploadStatus->isFinished(),
            'isCancelled' => $this->isCancelled($agentKey, $snapshot),
            'status' => $esxUploadStatus->getStatus(),
            'uploadedSize' => $esxUploadStatus->getUploadedSize(),
            'totalSize' => $esxUploadStatus->getTotalSize(),
            'datastore' => $esxUploadStatus->getDatastore(),
            'directory' => $esxUploadStatus->getDirectory(),
            'vmdks' => $esxUploadStatus->getVmdks(),
            'currentVmdk' => $esxUploadStatus->getCurrentVmdk(),
            'error' => $esxUploadStatus->getError()
        ];
    }

    /**
     * @param string $agentKey
     * @param int $snapshot
     * @param string $connectionName
     */
    private function createRestore(string $agentKey, int $snapshot, string $connectionName)
    {
        $restore = $this->restoreService->create(
            $agentKey,
            $snapshot,
            RestoreType::ESX_UPLOAD,
            $this->dateTimeService->getTime(),
            ['connectionName' => $connectionName]
        );
        $this->restoreService->add($restore);
        $this->restoreService->save();
    }

    /**
     * @param string $agentKey
     * @param int $snapshot
     */
    private function removeRestore(string $agentKey, int $snapshot)
    {
        // Restore must be pulled fresh from the UIRestores file, otherwise the RestoreRepository's merge will
        // just add the restore back into the file while saving
        $restore = $this->restoreService->find($agentKey, $snapshot, RestoreType::ESX_UPLOAD);
        if ($restore) {
            $this->restoreService->delete($restore);
            $this->restoreService->save();
        }
    }

    /**
     * @param CloneSpec $cloneSpec
     * @return array
     */
    private function getFilesToUpload(CloneSpec $cloneSpec): array
    {
        $agentHir = $this->agentHirFactory->create($cloneSpec->getAssetKey(), $cloneSpec->getTargetMountpoint());
        $result = $agentHir->execute();
        if ($result->failed()) {
            throw new Exception('Hir Failed:' . $result->getException());
        }

        $vmdks = $this->filesystem->glob($cloneSpec->getTargetMountpoint() . '/*.vmdk');

        foreach ($vmdks as $vmdkFile) {
            // The vmdk contains the .datto filename that it references
            if (!preg_match('/"([\w-]+\.datto)"/', $this->filesystem->fileGetContents($vmdkFile), $matches)) {
                throw new Exception('Unable to find the .datto file for ' . $vmdkFile);
            }
            $dattoFile = $cloneSpec->getTargetMountpoint() . '/' . $matches[1];

            $imagesToUpload[] = [
                'vmdk' => ['path' => $vmdkFile, 'size' => $this->filesystem->getSize($vmdkFile)],
                'datto' => ['path' => $dattoFile, 'size' => $this->filesystem->getSize($dattoFile)]
            ];
        }

        if (!isset($imagesToUpload)) {
            throw new Exception('No disk images to upload. Can\'t continue.');
        }

        return $imagesToUpload;
    }
}
