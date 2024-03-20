<?php

namespace Datto\Agentless;

/**
 * Represents results of a copy operation.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class SectorCopyResult
{
    /** @var  int total bytes read from source */
    private $bytesRead;

    /** @var  int total bytes written to destination */
    private $bytesWritten;

    /** @var  int total bytes expected in the copy operation */
    private $bytesExpectedTotal;

    /*** @var bool true if copy operation was aborted */
    private $aborted;

    /**
     * @param int $bytesRead total bytes read
     * @param int $bytesWritten total bytes written
     * @param int $bytesExpectedTotal total expected bytes in the operation
     * @param bool $aborted
     */
    public function __construct($bytesRead, $bytesWritten, $bytesExpectedTotal, $aborted)
    {
        $this->bytesRead = $bytesRead;
        $this->bytesWritten = $bytesWritten;
        $this->aborted = $aborted;
        $this->bytesExpectedTotal = $bytesExpectedTotal;
    }

    /**
     * Total bytes read from source.
     * @return int
     */
    public function getBytesRead()
    {
        return $this->bytesRead;
    }

    /**
     * Total bytes written to destination.
     * @return int
     */
    public function getBytesWritten()
    {
        return $this->bytesWritten;
    }

    /**
     * Total bytes expected in the copy operation.
     * @return int
     */
    public function getBytesExpectedTotal()
    {
        return $this->bytesExpectedTotal;
    }

    /**
     * True if copy operation was aborted.
     * @return bool
     */
    public function isAborted()
    {
        return $this->aborted;
    }
}
