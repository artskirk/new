<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Azure\Storage\AzCopy;
use Datto\Azure\Storage\AzCopyContextFactory;
use Datto\Config\DeviceState;
use Datto\Log\LoggerAwareTrait;
use Datto\System\Transaction\TransactionException;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service class for dealing with public cloud exports. This class is responsible for creating the restore,
 * exporting it to VHD using the public cloud APIs, and then uploading the VHDs to the public cloud.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudManager implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var PublicCloudExporter */
    private $publicCloudExporter;

    /** @var AzCopyContextFactory */
    private $azCopyContextFactory;

    /** @var AzCopy */
    private $azCopy;

    /** @var DeviceState */
    private $deviceState;

    /** @var PublicCloudRestore */
    private $restoreState;

    /** @var PublicCloudRestoreStatusFactory */
    private $publicCloudRestoreStatusFactory;

    public function __construct(
        PublicCloudExporter $publicCloudExporter,
        AzCopyContextFactory $azCopyContextFactory,
        AzCopy $azCopy,
        DeviceState $deviceState,
        PublicCloudRestore $restoreState,
        PublicCloudRestoreStatusFactory $publicCloudRestoreStatusFactory
    ) {
        $this->publicCloudExporter = $publicCloudExporter;
        $this->azCopyContextFactory = $azCopyContextFactory;
        $this->azCopy = $azCopy;
        $this->deviceState = $deviceState;
        $this->restoreState = $restoreState;
        $this->publicCloudRestoreStatusFactory = $publicCloudRestoreStatusFactory;
    }

    /**
     * Builds the public cloud export by creating the restore, uploading it, and then cleaning up.
     */
    public function build(
        string $assetKey,
        int $snapshot,
        string $vmGeneration,
        bool $enableAgentInRestoredVm,
        array $sasURIMap = [],
        bool $autoRemove = true
    ) {
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('PUB0000 Creating public cloud export', ['snapshot' => $snapshot]);

        try {
            $this->addDeviceState($assetKey, $snapshot);

            $this->publicCloudExporter->export(
                $assetKey,
                $snapshot,
                $vmGeneration,
                $enableAgentInRestoredVm,
                $sasURIMap,
                $autoRemove,
                $this->getStatusId($assetKey, $snapshot)
            );

            $this->setDeviceState($assetKey, $snapshot, PublicCloudRestore::STATE_DONE);
        } catch (Throwable $t) {
            $this->logger->warning('PUB0004 Error during public cloud export', ['exception' => $t]);
            $this->setDeviceState($assetKey, $snapshot, PublicCloudRestore::STATE_FAILED);
        }
    }

    /**
     * Returns associative array with VHD filenames as key and array with size and os as values.
     *
     * @return array<string, array>
     */
    public function getInfo(string $assetKey, int $snapshot, string $vmGeneration): array
    {
        $mappedSizes = [];

        try {
            $exportedFiles = $this->publicCloudExporter->export($assetKey, $snapshot, $vmGeneration, false, [], false);
            $mappedSizes = $this->publicCloudExporter->parseExportedFiles($assetKey, $exportedFiles);
        } catch (Throwable $t) {
            $this->logger->warning('PUB0005 Error during public cloud sizing', ['exception' =>$t]);
        } finally {
            $this->publicCloudExporter->remove($assetKey, $snapshot);
        }

        return $mappedSizes;
    }

    /**
     * Cleans up the restore and the export if it wasn't already cleaned up.
     */
    public function remove(string $assetKey, int $snapshot)
    {
        $this->logger->setAssetContext($assetKey);
        $this->logger->info('PUB0001 Removing public cloud export', ['snapshot' => $snapshot]);
        try {
            $this->publicCloudExporter->remove($assetKey, $snapshot);
        } catch (Throwable $e) {
            if ($e instanceof TransactionException && $e->getPrevious() !== null) {
                $e = $e->getPrevious();
            }

            if ($e->getMessage() === 'Failed to locate agentInfo.') {
                $this->logger->warning('PUB0015 Failed to locate agentInfo', ['snapshot' => $snapshot]);
            } else {
                throw $e;
            }
        }
        $this->removeDeviceState($assetKey, $snapshot);
        $this->cleanupAzCopyState($assetKey, $snapshot);
    }

    /**
     * Returns the status of a running public cloud restore.
     */
    public function getStatus(string $assetKey, int $snapshot): PublicCloudRestoreStatus
    {
        $this->logger->setAssetContext($assetKey);
        $this->deviceState->loadRecord($this->restoreState);
        $restoreState = $this->restoreState->getRestoreState($assetKey, $snapshot);
        $status = $this->publicCloudRestoreStatusFactory->create();
        $status->setState($restoreState);
        if ($restoreState === PublicCloudRestore::STATE_UPLOADING) {
            $azCopyContext = $this->azCopyContextFactory->create($this->getStatusId($assetKey, $snapshot));
            try {
                $azCopyStatus = $this->azCopy->getStatus($azCopyContext);
                $status->setAzCopyStatus($azCopyStatus);
            } catch (Throwable $t) {
                // Not getting a copy status just means we haven't started uploading yet.
            }
        }

        return $status;
    }

    /**
     * The upload ID used to get status from a public cloud upload.
     */
    private function getStatusId(string $assetKey, string $snapshot): string
    {
        return "{$assetKey}_{$snapshot}";
    }

    private function setDeviceState(string $assetKey, int $snapshot, string $state)
    {
        $this->deviceState->loadRecord($this->restoreState);
        $this->restoreState->setRestoreState($assetKey, $snapshot, $state);
        $this->deviceState->saveRecord($this->restoreState);
    }

    private function addDeviceState(string $assetKey, int $snapshot)
    {
        $this->deviceState->loadRecord($this->restoreState);
        $this->restoreState->addRestore($assetKey, $snapshot);
        $this->deviceState->saveRecord($this->restoreState);
    }

    private function removeDeviceState(string $assetKey, int $snapshot)
    {
        try {
            $this->deviceState->loadRecord($this->restoreState);
            $this->restoreState->removeRestore($assetKey, $snapshot);
            $this->deviceState->saveRecord($this->restoreState);
        } catch (Throwable $t) {
            // If there is no state, remove will throw an error, that's ok.
        }
    }

    private function cleanupAzCopyState(string $asset, int $snapshot)
    {
        try {
            $azCopyContext = $this->azCopyContextFactory->create($this->getStatusId($asset, $snapshot));
            $azCopyStatus = $this->azCopy->getStatus($azCopyContext);
        } catch (Throwable $t) {
            // If there is no AzCopyStatus because its not running getStatus will return
            // an error. That's ok, nothing to clean up.
        }
    }
}
