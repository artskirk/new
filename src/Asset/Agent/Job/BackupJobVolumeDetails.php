<?php

namespace Datto\Asset\Agent\Job;

/**
 * Details about a specific Job.
 *
 * @author John Roland <jroland@datto.com>
 */
class BackupJobVolumeDetails
{
    /** @var string */
    private $dateTime;

    /** @var string */
    private $status;

    /** @var string */
    private $volumeGuid;

    /** @var string */
    private $volumeMountPoint;

    /** @var string */
    private $volumeType;

    /** @var string */
    private $filesystemType;

    /** @var int */
    private $bytesTotal;

    /** @var int */
    private $bytesSent;

    /** @var int */
    private $spaceTotal;

    /** @var int */
    private $spaceFree;

    /** @var int */
    private $spaceUsed;

    /**
     * Details constructor.
     * @param string $dateTime
     * @param string $status
     * @param string $volumeGuid
     * @param string $volumeMountPoint
     * @param string $volumeType
     * @param string $filesystemType
     * @param int $bytesTotal
     * @param int $bytesSent
     * @param int $spaceTotal
     * @param int $spaceFree
     * @param int $spaceUsed
     */
    public function __construct(
        $dateTime,
        $status,
        $volumeGuid,
        $volumeMountPoint,
        $volumeType,
        $filesystemType,
        $bytesTotal,
        $bytesSent,
        $spaceTotal,
        $spaceFree,
        $spaceUsed
    ) {
        $this->dateTime = $dateTime;
        $this->status = $status;
        $this->volumeGuid = $volumeGuid;
        $this->volumeMountPoint = $volumeMountPoint;
        $this->volumeType = $volumeType;
        $this->filesystemType = $filesystemType;
        $this->bytesTotal = $bytesTotal;
        $this->bytesSent = $bytesSent;
        $this->spaceTotal = $spaceTotal;
        $this->spaceFree = $spaceFree;
        $this->spaceUsed = $spaceUsed;
    }

    /**
     * @return string
     */
    public function getDateTime()
    {
        return $this->dateTime;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getVolumeGuid()
    {
        return $this->volumeGuid;
    }

    /**
     * @return string
     */
    public function getVolumeType()
    {
        return $this->volumeType;
    }

    /**
     * @return string
     */
    public function getVolumeMountPoint()
    {
        return $this->volumeMountPoint;
    }

    /**
     * @return string
     */
    public function getFilesystemType()
    {
        return $this->filesystemType;
    }

    /**
     * @return int
     */
    public function getBytesTotal()
    {
        return $this->bytesTotal;
    }

    /**
     * @return int
     */
    public function getBytesSent()
    {
        return $this->bytesSent;
    }

    /**
     * @return int
     */
    public function getSpaceTotal()
    {
        return $this->spaceTotal;
    }

    /**
     * @return int
     */
    public function getSpaceFree()
    {
        return $this->spaceFree;
    }

    /**
     * @return int
     */
    public function getSpaceUsed()
    {
        return $this->spaceUsed;
    }
}
