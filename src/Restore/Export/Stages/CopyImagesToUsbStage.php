<?php

namespace Datto\Restore\Export\Stages;

use Datto\Common\Utility\Filesystem;
use Datto\Common\Resource\Sleep;
use Datto\ImageExport\ImageType;
use Datto\Restore\Export\Usb\ExportCancelledException;
use Datto\Restore\Export\Usb\UsbExportProgressService;
use Datto\System\Pv\MonitorablePvProcess;
use Exception;

/**
 * Copy the image files for the export onto the USB drive, recording progress as we go.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class CopyImagesToUsbStage extends AbstractStage
{
    const PROGRESS_INTERVAL_SECONDS = 1;
    const CANCEL_WAIT_SECONDS = 5;

    /** @var Filesystem */
    private $filesystem;

    /** @var MonitorablePvProcess */
    private $monitorableProcess;

    /** @var Sleep */
    private $sleep;

    /** @var UsbExportProgressService */
    private $usbExportProgressService;

    public function __construct(
        Filesystem $filesystem,
        MonitorablePvProcess $monitorableProcess,
        Sleep $sleep,
        UsbExportProgressService $usbExportProgressService
    ) {
        $this->filesystem = $filesystem;
        $this->monitorableProcess = $monitorableProcess;
        $this->sleep = $sleep;
        $this->usbExportProgressService = $usbExportProgressService;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $sourcePath = $this->context->getMountPoint();
        $imageFiles = $this->getFileListFromPatterns($sourcePath);
        $pattern = implode(", ", $this->getImagePatterns());

        if (empty($imageFiles)) {
            $this->logger->error('USB0200 No image files found matching pattern', ['pattern' => $pattern]);
        } else {
            $this->transferImageFiles($imageFiles);
        }
        $this->markTransferComplete();
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $files = $this->getFileListFromPatterns($this->context->getUsbInformation()->getMountPoint());
        foreach ($files as $file) {
            $this->filesystem->unlink($file);
        }
    }

    /**
     * @param array $imageFiles List of files to copy
     */
    private function transferImageFiles(array $imageFiles)
    {
        $targetPath = $this->context->getUsbInformation()->getMountPoint();
        $sourcePath = $this->context->getMountPoint();

        $fileNum = 0;
        $fileCount = count($imageFiles);
        $totalBytesTransferred = 0;
        $sourceSize = $this->getDirectorySize($sourcePath);

        // TODO: Use real transfer method that supports progress reporting and killing mid-copy.
        foreach ($imageFiles as $file) {
            $this->checkForCancellation();

            $fileNum++;
            $this->logger->info('USB0201 Copying file to USB drive', ['file' => $file, 'path' => $targetPath]);

            $currentFileTransferedBytes = 0;
            $targetFile = $targetPath . '/' . basename($file);
            $this->monitorableProcess->startProcess($file, $targetFile, false);

            while ($this->monitorableProcess->isRunning()) {
                $this->checkForCancellation();
                $this->sleep->sleep(static::PROGRESS_INTERVAL_SECONDS);

                $progress = $this->monitorableProcess->getProgressData();
                // TODO: Get rid of temporary status tracking in favor of something less crappy.
                $currentFileTransferedBytes = $progress->getBytesTransferred();

                $this->usbExportProgressService->setTransferState(
                    $totalBytesTransferred + $currentFileTransferedBytes,
                    $sourceSize,
                    $progress->getTransferRate(),
                    $fileNum,
                    $fileCount
                );
            }
            $results = $this->monitorableProcess->getResults();
            if ($results->getExitCode() !== 0) {
                throw new Exception($results->getExitCodeText());
            }
            $totalBytesTransferred += $currentFileTransferedBytes;
        }
    }

    private function markTransferComplete()
    {
        $this->usbExportProgressService->setFinishingState();
    }

    /**
     * @param string $baseDirectory
     * @return string[]
     */
    private function getFileListFromPatterns(string $baseDirectory): array
    {
        $files = [];
        foreach ($this->getImagePatterns() as $pattern) {
            $patternMatches = $this->filesystem->glob($baseDirectory . '/' . $pattern);
            $files = array_merge($files, $patternMatches);
        }
        return $files;
    }

    private function getDirectorySize(string $directory): int
    {
        $files = $this->getFileListFromPatterns($directory);

        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += $this->filesystem->getSize($file);
        }
        return $totalSize;
    }

    private function checkForCancellation()
    {
        if ($this->context->isCancelled()) {
            $this->usbExportProgressService->setCancelingState();
            if ($this->monitorableProcess->isRunning()) {
                $this->monitorableProcess->killProcess();
                $this->sleep->sleep(static::CANCEL_WAIT_SECONDS);
            }
            $this->logger->info("USB0202 USB export cancelled");
            throw new ExportCancelledException("Cancelled by user");
        }
    }

    private function getImagePatterns(): array
    {
        $imageType = $this->context->getImageType()->value();

        switch ($imageType) {
            case ImageType::VMDK:
                return [
                    '*.vmdk',
                    '*.vmx'
                ];
            case ImageType::VMDK_LINKED:
                return [
                    '*.vmdk',
                    '*.datto',
                    '*.vmx'
                ];
            case ImageType::VHD:
                return [
                    '*.vhd',
                    '*.vmx'
                ];
            case ImageType::VHDX:
                return [
                    '*.vhdx',
                    '*.vmx'
                ];
            default:
                throw new Exception(sprintf(
                    'File patterns are not defined for "%s" image type',
                    $imageType
                ));
        }
    }
}
