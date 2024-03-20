<?php

namespace Datto\Agentless;

/**
 * Represents source/destination byte offsets used in a
 * copy operation.
 *
 * @author Jason Lodice <JLodice@datto.com>
 */
class OffsetMap
{
    /*** @var int starting byte location for read operation on source */
    private $sourceByteOffset;

    /*** @var int starting byte location for write operation on destination */
    private $destByteOffset;

    /*** @var int number of bytes to be copied */
    private $byteLength;

    /**
     * @param int $sourceByteOffset starting byte location for read operation on source
     * @param int $destByteOffset starting byte location for write operation on destination
     * @param $byteLength number of bytes to be copied
     */
    public function __construct($sourceByteOffset, $destByteOffset, $byteLength)
    {
        $this->sourceByteOffset = $sourceByteOffset;
        $this->destByteOffset = $destByteOffset;
        $this->byteLength = $byteLength;
    }

    /**
     * @return int
     */
    public function getSourceByteOffset()
    {
        return $this->sourceByteOffset;
    }

    /**
     * @return int
     */
    public function getDestByteOffset()
    {
        return $this->destByteOffset;
    }

    /**
     * @return int
     */
    public function getByteLength()
    {
        return $this->byteLength;
    }
}
