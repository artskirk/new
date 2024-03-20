<?php

namespace Datto\Restore\Export\Usb;

/**
 * Progress object for USB exports
 *
 * @author Peter Geer <pgeer@datto.com>
 * @author Christopher LaRosa <clarosa@datto.com>
 */
class UsbExportProgress
{
    const STATE_STARTING = 'starting';
    const STATE_TRANSFER = 'transfer';
    const STATE_FINISHING = 'finishing';
    const STATE_CANCELING = 'canceling';
    const STATE_SUCCESS = 'success';
    const STATE_FAILED = 'failed';

    /** @var string */
    private $currentState;

    /** @var string */
    private $message;

    /** @var int */
    private $currentBytes;

    /** @var int */
    private $totalBytes;

    /** @var string */
    private $transferRate;

    /** @var int */
    private $currentFile;

    /** @var int */
    private $totalFiles;

    /**
     * @param string $currentState One of the STATE constants defined above.
     * @param int $currentBytes
     * @param int $totalBytes
     * @param string $transferRate Transfer rate and units (e.g. "50MB/s")
     * @param int $currentFile
     * @param int $totalFiles
     * @param string $message
     */
    public function __construct(
        string $currentState,
        string $message = '',
        int $currentBytes = 0,
        int $totalBytes = 0,
        string $transferRate = '',
        int $currentFile = 0,
        int $totalFiles = 0
    ) {
        $this->currentState = $currentState;
        $this->message = $message;
        $this->currentBytes = $currentBytes;
        $this->totalBytes = $totalBytes;
        $this->transferRate = $transferRate;
        $this->currentFile = $currentFile;
        $this->totalFiles = $totalFiles;
    }

    /**
     * @return string One of the STATE constants defined in this class.
     */
    public function getCurrentState(): string
    {
        return $this->currentState;
    }

    /**
     * @return int
     */
    public function getCurrentBytes(): int
    {
        return $this->currentBytes;
    }

    /**
     * @return int
     */
    public function getTotalBytes(): int
    {
        return $this->totalBytes;
    }

    /**
     * Gets the transfer rate including the units (e.g. "50MB/s").
     *
     * @return string
     */
    public function getTransferRate(): string
    {
        return $this->transferRate;
    }

    /**
     * @return int
     */
    public function getCurrentFile(): int
    {
        return $this->currentFile;
    }

    /**
     * @return int
     */
    public function getTotalFiles(): int
    {
        return $this->totalFiles;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return int
     */
    public function getPercent(): int
    {
        return $this->totalBytes ? (float)$this->currentBytes / $this->totalBytes * 100 : 0;
    }
}
