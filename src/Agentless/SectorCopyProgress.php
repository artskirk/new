<?php

namespace Datto\Agentless;

/**
 * Represents progress of a copy operation.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class SectorCopyProgress
{
    /** @var  int total bytes read from source */
    private $newBytesRead;

    /** @var  int total bytes written to destination */
    private $newBytesWritten;

    /**
     * @param int $newBytesRead number of new bytes read since last progress
     * @param int $newBytesWritten number of new bytes written since last progress
     */
    public function __construct($newBytesRead, $newBytesWritten)
    {
        $this->newBytesRead = $newBytesRead;
        $this->newBytesWritten = $newBytesWritten;
    }

    /**
     * Total bytes read from source since last progress.
     * @return int
     */
    public function getNewBytesRead()
    {
        return $this->newBytesRead;
    }

    /**
     * Total bytes written to destination since last progress.
     * @return int
     */
    public function getNewBytesWritten()
    {
        return $this->newBytesWritten;
    }
}
