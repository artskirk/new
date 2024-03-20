<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Asset\Agent\AgentService;
use Datto\Asset\Agent\Volume;
use Datto\Asset\Agent\Volumes;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Filesystem\StitchfsMountFactory;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Context;
use Datto\Service\Restore\Export\ContextFactory;
use Datto\Utility\ByteUnit;
use Psr\Log\LoggerAwareInterface;

/**
 * Handles exporting a public cloud restore and returning status on it.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudExporter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const VM_GENERATION_V1 = 'V1';
    const VM_GENERATION_V2 = 'V2';

    /** @var AgentService */
    private $agentService;

    /** @var PublicCloudTransactionFactory */
    private $transactionFactory;

    /** @var StitchfsMountFactory */
    private $stichfsMountFactory;

    /** @var ContextFactory */
    private $contextFactory;

    /** @var Filesystem */
    private $filesystem;

    public function __construct(
        AgentService $agentService,
        PublicCloudTransactionFactory $transactionFactory,
        StitchfsMountFactory $stitchfsMountFactory,
        ContextFactory $contextFactory,
        Filesystem $filesystemInterface
    ) {
        $this->agentService = $agentService;
        $this->transactionFactory = $transactionFactory;
        $this->stichfsMountFactory = $stitchfsMountFactory;
        $this->contextFactory = $contextFactory;
        $this->filesystem = $filesystemInterface;
    }

    /**
     * Exports a snapshot as a series of VHD files that can be uploaded to the public cloud and
     *  returns a list of the resulting files to upload.
     */
    public function export(
        string $agentName,
        int $snapshotEpoch,
        string $vmGeneration,
        bool $enableAgentInRestoredVm,
        array $sasUriMap = [],
        bool $remove = false,
        string $statusId = ''
    ): array {
        $this->logger->setAssetContext($agentName);
        $this->logger->info(
            'PUB0008 Exporting public cloud image of type VHD',
            ['snapshot' => $snapshotEpoch]
        );

        $context = $this->getContext(
            $agentName,
            $snapshotEpoch,
            $enableAgentInRestoredVm,
            $vmGeneration,
            $sasUriMap,
            $statusId
        );

        $transaction = $this->transactionFactory->createExportTransaction($context);
        $transaction->commit();

        if ($remove) {
            $this->logger->debug('PUB0010 Cleaning up restore');
            $removeTransaction = $this->transactionFactory->createRemoveTransaction($context);
            $removeTransaction->commit();
        } else {
            $this->logger->debug('PUB0013 Skipping cleanup of restore');
        }

        $this->logger->info('PUB0009 Image exported successfully.');

        return $this->filesystem->glob(
            "{$context->getMountPoint()}/*.{$context->getImageType()->value()}"
        );
    }

    public function parseExportedFiles(string $assetKey, array $exportedFiles): array
    {
        $assocExportedFiles = [];
        $agent = $this->agentService->get($assetKey);
        $osFamily = $agent->getOperatingSystem()->getOsFamily()->value();
        $volumes = $agent->getVolumes();

        foreach ($exportedFiles as $file) {
            $key = basename($file, '.vhd');
            $size = $this->filesystem->getSize($file);
            $osVolume = $this->isOsVolume($key, $volumes) ? $osFamily : null;
            $assocExportedFiles[$key] = ['size' => $size, 'osVolume' => $osVolume];
        }

        return $assocExportedFiles;
    }

    /*
    * Cleans up an exported snapshot.
    */
    public function remove(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);
        $this->logger->info(
            'PUB0011 Removing image of type VHD',
            ['snapshot' => $snapshotEpoch]
        );

        $context = $this->getContext(
            $agentName,
            $snapshotEpoch,
            false
        );
        $transaction = $this->transactionFactory->createRemoveTransaction($context);
        $transaction->commit();

        $this->logger->info('PUB0012 Image removed successfully.');
    }

    /**
     * Create a context object for use in the export stages.
     */
    private function getContext(
        string $agentName,
        int $snapshotEpoch,
        bool $enableAgentInRestoredVm,
        string $vmGeneration = null,
        array $sasUriMap = [],
        string $statusId = ''
    ) : Context {
        $agent = $this->agentService->get($agentName);

        $fuseMounter = $this->stichfsMountFactory->create();

        $bootType = $vmGeneration === self::VM_GENERATION_V1 ? BootType::BIOS() : BootType::UEFI();
        $context = $this->contextFactory->create(
            $agent,
            $snapshotEpoch,
            ImageType::VHD(),
            $fuseMounter,
            $enableAgentInRestoredVm,
            is_null($vmGeneration) ? null : $bootType
        );

        // Azure requires images to be aligned to nearest MiB
        $context->setDiskSizeAlignToBytes(ByteUnit::MIB()->toByte(1));
        $context->setSasUriMap($sasUriMap);
        $context->setStatusId($statusId);

        return $context;
    }

    /**
     * @param string $vhdName
     * @param Volumes $volumes
     *
     * @return bool
     */
    private function isOsVolume(string $vhdName, Volumes $volumes): bool
    {
        foreach ($volumes as $volume) {
            $isOsVolume = $volume->isOsVolume();
            $isTheRightVolume = stripos($volume->getMountpoint(), $vhdName) !== false;

            if ($isOsVolume && $isTheRightVolume) {
                return true;
            }
        }

        return false;
    }
}
