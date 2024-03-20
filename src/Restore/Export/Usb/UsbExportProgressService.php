<?php

namespace Datto\Restore\Export\Usb;

use Datto\Common\Utility\Filesystem;
use Exception;

/**
 * Progress service for USB exports
 *
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class UsbExportProgressService
{
    // Note: the "Filesystem::putAtomic()" function is used to write to this
    // path, and therefore the path must always be on the same drive as "/tmp".
    const STATUS_FILE = "/tmp/usbExportStatus";

    /** @var Filesystem */
    private $filesystem;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        Filesystem $filesystem
    ) {
        $this->filesystem = $filesystem;
    }

    /**
     * Gets the current progress.
     *
     * @return UsbExportProgress
     */
    public function getProgress(): UsbExportProgress
    {
        if (!$this->filesystem->exists(self::STATUS_FILE)) {
            return new UsbExportProgress(UsbExportProgress::STATE_STARTING);
        }

        $progressContents = json_decode($this->filesystem->fileGetContents(self::STATUS_FILE), true);

        if (!$progressContents) {
            throw new Exception('Error reading USB export progress');
        }

        return new UsbExportProgress(
            $progressContents['state'],
            $progressContents['message'],
            $progressContents['currentBytes'],
            $progressContents['totalBytes'],
            $progressContents['transferRate'],
            $progressContents['currentFile'],
            $progressContents['totalFiles']
        );
    }

    /**
     * Sets the progress.
     *
     * @param UsbExportProgress $usbExportStatus
     */
    public function setProgress(UsbExportProgress $usbExportStatus)
    {
        $progressContents = [
            'state' => $usbExportStatus->getCurrentState(),
            'message' => $usbExportStatus->getMessage(),
            'currentBytes' => $usbExportStatus->getCurrentBytes(),
            'totalBytes' => $usbExportStatus->getTotalBytes(),
            'transferRate' => $usbExportStatus->getTransferRate(),
            'currentFile' => $usbExportStatus->getCurrentFile(),
            'totalFiles' => $usbExportStatus->getTotalFiles()
        ];

        $this->filesystem->putAtomic(self::STATUS_FILE, json_encode($progressContents));
    }

    /**
     * Sets the state to STARTING.
     */
    public function setStartingState()
    {
        $progress = new UsbExportProgress(UsbExportProgress::STATE_STARTING);
        $this->setProgress($progress);
    }

    /**
     * Sets the state to TRANSFER and sets progress information.
     *
     * @param int $currentBytes
     * @param int $totalBytes
     * @param string $transferRate
     * @param int $currentFile
     * @param int $totalFiles
     */
    public function setTransferState(
        int $currentBytes,
        int $totalBytes,
        string $transferRate,
        int $currentFile,
        int $totalFiles
    ) {
        $progress = new UsbExportProgress(
            UsbExportProgress::STATE_TRANSFER,
            '',
            $currentBytes,
            $totalBytes,
            $transferRate,
            $currentFile,
            $totalFiles
        );
        $this->setProgress($progress);
    }

    /**
     * Sets the state to FINISHING.
     */
    public function setFinishingState()
    {
        $progress = new UsbExportProgress(UsbExportProgress::STATE_FINISHING);
        $this->setProgress($progress);
    }

    /**
     * Sets the state to SUCCESS.
     */
    public function setSuccessState()
    {
        $progress = new UsbExportProgress(UsbExportProgress::STATE_SUCCESS);
        $this->setProgress($progress);
    }

    /**
     * Sets the state to FAILED and sets an error message.
     *
     * @param string $message
     */
    public function setFailedState(string $message)
    {
        $progress = new UsbExportProgress(UsbExportProgress::STATE_FAILED, $message);
        $this->setProgress($progress);
    }

    /**
     * Sets the state to CANCELING.
     */
    public function setCancelingState()
    {
        $progress = new UsbExportProgress(UsbExportProgress::STATE_CANCELING);
        $this->setProgress($progress);
    }
}
