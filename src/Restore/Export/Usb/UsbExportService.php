<?php

namespace Datto\Restore\Export\Usb;

use Datto\Asset\AssetService;
use Datto\ImageExport\BootType;
use Datto\ImageExport\ImageType;
use Datto\Log\LoggerAwareTrait;
use Datto\Metrics\Collector;
use Datto\Metrics\Metrics;
use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\Restore\Export\Usb\UsbExporter;
use Datto\Restore\RestoreType;
use Datto\Utility\Screen;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Throwable;

/**
 * Service for performing image exports to a USB drive.
 *
 * Note: The logic in this class was ported over directly from usbHandler.php.
 * It is slated for a rewrite in an upcoming ticket.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class UsbExportService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const SCREEN_NAME = 'usbSnapCopy';

    /** @var UsbExporter */
    private $usbExporter;

    /** @var Filesystem */
    private $filesystem;

    /** @var Sleep */
    private $sleep;

    /** @var Screen */
    private $screen;

    /** @var UsbExportProgressService */
    private $usbExportProgressService;

    /** @var Collector */
    private $collector;

    /** @var AssetService */
    private $assetService;

    public function __construct(
        UsbExporter $usbExporter,
        Filesystem $filesystem,
        Sleep $sleep,
        Screen $screen,
        UsbExportProgressService $usbExportProgressService,
        Collector $collector,
        AssetService $assetService
    ) {
        $this->usbExporter = $usbExporter;
        $this->filesystem = $filesystem;
        $this->sleep = $sleep;
        $this->screen = $screen;
        $this->usbExportProgressService = $usbExportProgressService;
        $this->collector = $collector;
        $this->assetService = $assetService;
    }

    /**
     * Run a USB export for the given agent, snapshot, and image format
     *
     * If the export fails with an exception, this will set the USB export
     * progress state to "failed" with the execption message.
     *
     * @param string $agentKeyName
     * @param int $snapshot
     * @param ImageType $format
     * @param BootType $bootType
     */
    public function exportImage(string $agentKeyName, int $snapshot, ImageType $format, BootType $bootType)
    {
        $this->logger->setAssetContext($agentKeyName);
        $this->logger->info("USB0001 Starting USB export.", ['snapshot' => $snapshot, 'format' => $format]);

        try {
            $this->prepareExporter($format, $bootType);
            $this->usbExporter->export($agentKeyName, $snapshot);
            $this->usbExportProgressService->setSuccessState();
        } catch (Throwable $throwable) {
            $previous = $throwable->getPrevious();
            $failureMessage = isset($previous) ? $previous->getMessage() : $throwable->getMessage();
            $this->usbExportProgressService->setFailedState($failureMessage);
            throw $throwable;
        }

        $this->logger->info('USB0004 USB export completed successfully.');
    }

    /**
     * Cancel a running export.
     *
     * @param string $agentKeyName
     * @param int $snapshot
     * @param ImageType $format
     */
    public function cancel(string $agentKeyName, int $snapshot, ImageType $format)
    {
        $this->logger->setAssetContext($agentKeyName);
        $this->logger->info("USB0010 Cancelling USB export.", ['snapshot' => $snapshot]);

        $this->prepareExporter($format);
        if ($this->screen->isScreenRunning(static::SCREEN_NAME)) {
            $this->usbExporter->cancel($agentKeyName, $snapshot);
        } else {
            $this->logger->debug("USB0011 Export process not running, cleaning up");
            $this->usbExporter->remove($agentKeyName, $snapshot);
        }

        $this->logger->info('USB0014 USB export cancelled successfully.');
    }

    /**
     * Asynchronously start an image export by running a command in the background.
     *
     * @param string $agentKeyName
     * @param int $snapshot
     * @param ImageType $format
     * @param BootType|null $bootType
     * @return bool
     */
    public function startAsync(string $agentKeyName, int $snapshot, ImageType $format, BootType $bootType = null): bool
    {
        $asset = $this->assetService->get($agentKeyName);

        $this->collector->increment(Metrics::RESTORE_STARTED, [
            'type' => Metrics::RESTORE_TYPE_IMAGE_EXPORT_USB,
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);
        $this->collector->increment(Metrics::RESTORE_IMAGE_EXPORT_USB_STARTED, [
            'imageType' => $format->value(),
            'is_replicated' => $asset->getOriginDevice()->isReplicated(),
        ]);

        if ($this->filesystem->exists(UsbLock::LOCK_FILE)) {
            throw new Exception('There is currently a USB transfer in progress.');
        }

        // NOTE: --no-interaction is required to prevent the command for prompting for encrypted agent password
        $command = [
            'snapctl',
            'export:usb:create',
            '--no-interaction',
            $agentKeyName,
            $snapshot,
            $format->value(),
        ];
        if ($bootType) {
            $command[] = '--boot-type';
            $command[] = $bootType->value();
        }

        if (!$this->screen->isScreenRunning(static::SCREEN_NAME)) {
            $this->usbExportProgressService->setStartingState();

            return $this->screen->runInBackground($command, static::SCREEN_NAME);
        }
        return false;
    }

    /**
     * Determine if there is an export process currently running.
     *
     * @return bool
     */
    public function isExportAborted(): bool
    {
        $progress = $this->usbExportProgressService->getProgress();
        $progressNotStarted = $progress->getCurrentState() === UsbExportProgress::STATE_STARTING;
        return !$this->screen->isScreenRunning(static::SCREEN_NAME) && $progressNotStarted;
    }

    private function prepareExporter(ImageType $imageType, BootType $bootType = null)
    {
        $this->usbExporter->setImageType($imageType);
        $this->usbExporter->setBootType($bootType);
    }
}
