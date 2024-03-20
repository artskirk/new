<?php

namespace Datto\Asset\Agent;

/**
 * This class holds metadata for the volumes in the .include key file. We couldn't add on to the existing key file
 * because the structure of key files can't change right now.
 *
 * @author Peter Salu <psalu@datto.com>
 */
class VolumeMetadata
{
    /** @var  string */
    private $mountPoint;

    /** @var string */
    private $guid;

    /**
     * @param string $mountPoint
     * @param string $guid
     */
    public function __construct($mountPoint, $guid)
    {
        $this->mountPoint = $mountPoint;
        $this->guid = $guid;
    }

    /**
     * @return string
     */
    public function getMountpoint()
    {
        return $this->mountPoint;
    }

    /**
     * @return string
     */
    public function getGuid()
    {
        return $this->guid;
    }

    public function toArray(): array
    {
        return [
            'guid' => $this->getGuid(),
            'mountPoint' => $this->getMountpoint()
        ];
    }

    /**
     * String representation needed for array_diff.
     * @return string
     */
    public function __toString()
    {
        return (string) var_export($this, true);
    }
}
