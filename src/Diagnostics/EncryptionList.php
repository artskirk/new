<?php

namespace Datto\Diagnostics;

/**
 * Class to represent the encryption list properties
 * of an agent.
 *
 * @author John Fury Christ <jchrist@datto.com>
 */
class EncryptionList
{
    /** @var string Name of DevMapper e.g. 532044768a4411e5824f806e6f6e6963-crypt-e97fc699 */
    private $devMapper;

    /** @var string[] Name of the loop, e.g. loop0 */
    private $loop;

    /** @var string[] Path of the .datto file, e.g. /homePool/10.70.71.83-active/532044768a4411e5824f806e6f6e6963.detto) */
    private $dettoFile;

    /** @var string[] Path of the mountpoint, e.g. /datto/mounts/10.70.71.83/13-18-19-Oct-11-17/C */
    private $mountPoint;

    /**
     * @param string $devMapper Name of DevMapper
     * @param string[] $loop Name of the loop
     * @param string[] $dettoFile Path of the .detto file
     * @param string[] $mountPoint Number of bits, e.g. 64
     */
    public function __construct(string $devMapper, array $loop, array $dettoFile, array $mountPoint)
    {
        $this->devMapper = $devMapper;
        $this->loop = $loop;
        $this->dettoFile = $dettoFile;
        $this->mountPoint = $mountPoint;
    }

    /**
     * @return string
     */
    public function getDevMapper(): string
    {
        return $this->devMapper;
    }

    /**
     * @return string[]
     */
    public function getLoop(): array
    {
        return $this->loop;
    }

    /**
     * @return string[]
     */
    public function getDettoFile(): array
    {
        return $this->dettoFile;
    }

    /**
     * @return string[]
     */
    public function getMountPoint(): array
    {
        return $this->mountPoint;
    }
}
