<?php

namespace Datto\Restore\Export\Usb;

use Datto\Asset\Agent\AgentService;
use Datto\Common\Utility\Filesystem\AbstractFuseOverlayMount;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\ImageExport\Status;
use Datto\Filesystem\StitchfsMountFactory;
use Datto\Filesystem\TransparentMountFactory;
use Datto\Log\LoggerAwareTrait;
use Datto\Common\Utility\Filesystem;
use Datto\Restore\Export\ImageExporter;
use Psr\Log\LoggerAwareInterface;

/**
 * Base class for exporting an image type to a USB drive.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UsbExporter implements ImageExporter, LoggerAwareInterface
{
    use LoggerAwareTrait;

    // TODO: Probably find a better place for this
    const CANCEL_FILE = 'cancelExport';

    private Filesystem $filesystem;
    private AgentService $agentService;
    private UsbExportTransactionFactory $transactionFactory;
    private TransparentMountFactory $transparentMountFactory;
    private StitchfsMountFactory $stitchfsMountFactory;
    private ?BootType $bootType;
    private ImageType $imageType;

    public function __construct(
        AgentService $agentService,
        Filesystem $filesystem,
        UsbExportTransactionFactory $transactionFactory,
        TransparentMountFactory $transparentMountFactory,
        StitchfsMountFactory $stitchfsMountFactory
    ) {
        $this->agentService = $agentService;
        $this->filesystem = $filesystem;
        $this->transactionFactory = $transactionFactory;
        $this->transparentMountFactory = $transparentMountFactory;
        $this->stitchfsMountFactory = $stitchfsMountFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function export(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);

        $context = $this->getContext($agentName, $snapshotEpoch);

        $cancelFlag = $context->getCloneMountPoint() . '/' . static::CANCEL_FILE;

        $shouldCancel = function () use ($context) {
            return $context->isCancelled();
        };
        $cancelCleanup = function () use ($cancelFlag) {
            $this->filesystem->unlinkIfExists($cancelFlag);
        };

        $transaction = $this->transactionFactory->createExportTransaction($context);
        $transaction->setOnCancelCallback($shouldCancel, $cancelCleanup);

        $transaction->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function repair(string $agentName, int $snapshotEpoch)
    {
        // not applicable for USB
    }

    /**
     * Remove any export artifacts.  This is used to clean up from failed exports.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     */
    public function remove(string $agentName, int $snapshotEpoch)
    {
        $this->logger->setAssetContext($agentName);

        $context = $this->getContext($agentName, $snapshotEpoch);
        $transaction = $this->transactionFactory->createRemoveTransaction($context);
        $transaction->commit();
    }

    /**
     * Cancel a running export.
     *
     * @param string $agentName
     * @param int $snapshotEpoch
     */
    public function cancel(string $agentName, int $snapshotEpoch)
    {
        $cancelFile = $this->getContext($agentName, $snapshotEpoch)->getCloneMountPoint() . '/' . static::CANCEL_FILE;
        $this->filesystem->touch($cancelFile);
    }

    /**
     * {@inheritdoc}
     */
    public function isExported(string $agentName, int $snapshotEpoch): bool
    {
        return false;
    }

    public function setBootType(BootType $bootType = null)
    {
        $this->bootType = $bootType;
    }

    public function setImageType(ImageType $imageType)
    {
        $this->imageType = $imageType;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus(string $agentName, int $snapshotEpoch): Status
    {
        return new Status(true);
    }

    private function getFuseMounter(): AbstractFuseOverlayMount
    {
        if ($this->imageType === ImageType::VMDK_LINKED()) {
            return $this->transparentMountFactory->create();
        } else {
            return $this->stitchfsMountFactory->create();
        }
    }

    /**
     * Create a context object for use in the export stages.
     */
    private function getContext(string $agentName, int $snapshotEpoch): UsbExportContext
    {
        $agent = $this->agentService->get($agentName);

        return new UsbExportContext(
            $agent,
            $snapshotEpoch,
            $this->imageType,
            $this->getFuseMounter(),
            $this->bootType
        );
    }
}
