<?php

namespace Datto\Service\Restore\Export\PublicCloud;

use Datto\Azure\Storage\AzCopyStatus;
use JsonSerializable;

/**
 * Encapsulates the status information for a running Public Cloud restore.
 *
 * @author Chad Barbe <cbarbe@datto.com>
 */
class PublicCloudRestoreStatus implements JsonSerializable
{
    /** @var string */
    private $state = '';

    /** @var Int */
    private $totalPercentComplete = 0;

    /** @var AzCopyStatus */
    private $azCopyStatus;

    /**
     * @return AzCopyStatus|null
     */
    public function getAzCopyStatus()
    {
        return $this->azCopyStatus;
    }

    public function setAzCopyStatus(AzCopyStatus $azCopyStatus)
    {
        $this->azCopyStatus = $azCopyStatus;
        $totalSize = $this->azCopyStatus->getTotalSize();
        $totalUploaded = $this->azCopyStatus->getTotalUploaded();
        $this->totalPercentComplete = 0;

        if ($totalSize > 0) {
            $this->totalPercentComplete = round(($totalUploaded / $totalSize), 4) * 100;
        }

        if (!$this->azCopyStatus->succeeded()) {
            $this->setState(PublicCloudRestore::STATE_FAILED);
        }
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state)
    {
        $this->state = $state;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $azCopyStatus = $this->serializeAzCopyStatus();
        return [
            'state' => $this->getState(),
            'totalPercentComplete' => $this->getTotalPercentComplete(),
            'copyStatus' => $azCopyStatus
        ];
    }

    public function serializeAzCopyStatus(): array
    {
        $azCopyStatus = $this->getAzCopyStatus();

        if (is_null($azCopyStatus)) {
            return [];
        }

        return [
            'serverBusyPercentage' => $azCopyStatus->getServerBusyPercentage(),
            'networkErrorPercentage' => $azCopyStatus->getNetworkErrorPercentage(),
            'bytesExpected' => $azCopyStatus->getBytesExpected(),
            'bytesTransferred' => $azCopyStatus->getBytesTransferred(),
            'percentComplete' => $azCopyStatus->getPercentComplete(),
            'totalSize' => $azCopyStatus->getTotalSize(),
            'totalUploaded' => $azCopyStatus->getTotalUploaded(),
            'totalFiles' => $azCopyStatus->getTotalFiles(),
            'fileNumber' => $azCopyStatus->getFileNumber(),
            'filePath' => $azCopyStatus->getFilePath()
        ];
    }

    public function getTotalPercentComplete()
    {
        return $this->totalPercentComplete;
    }
}
