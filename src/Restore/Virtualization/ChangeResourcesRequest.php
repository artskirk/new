<?php

namespace Datto\Restore\Virtualization;

/**
 * @author Chad Kosie <ckosie@datto.com>
 */
class ChangeResourcesRequest
{
    /** @var int|null */
    private $cpuCount;

    /** @var int|null */
    private $memoryInMB;

    /** @var string|null */
    private $storageController;

    /** @var string|null */
    private $networkMode;

    /** @var string|null */
    private $videoController;

    /** @var string|null */
    private $networkController;

    /**
     * @return int|null
     */
    public function getCpuCount()
    {
        return $this->cpuCount;
    }

    /**
     * @param int $cpuCount
     * @return ChangeResourcesRequest
     */
    public function setCpuCount(int $cpuCount): ChangeResourcesRequest
    {
        $this->cpuCount = $cpuCount;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getMemoryInMB()
    {
        return $this->memoryInMB;
    }

    /**
     * @param int $memoryInMB
     * @return ChangeResourcesRequest
     */
    public function setMemoryInMB(int $memoryInMB): ChangeResourcesRequest
    {
        $this->memoryInMB = $memoryInMB;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStorageController()
    {
        return $this->storageController;
    }

    /**
     * @param string $storageController
     * @return ChangeResourcesRequest
     */
    public function setStorageController(string $storageController): ChangeResourcesRequest
    {
        $this->storageController = $storageController;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getNetworkMode()
    {
        return $this->networkMode;
    }

    /**
     * @param string $networkMode
     * @return ChangeResourcesRequest
     */
    public function setNetworkMode(string $networkMode): ChangeResourcesRequest
    {
        $this->networkMode = $networkMode;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getVideoController()
    {
        return $this->videoController;
    }

    /**
     * @param string $videoController
     * @return ChangeResourcesRequest
     */
    public function setVideoController(string $videoController): ChangeResourcesRequest
    {
        $this->videoController = $videoController;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getNetworkController()
    {
        return $this->networkController;
    }

    public function setNetworkController(string $networkController): ChangeResourcesRequest
    {
        $this->networkController = $networkController;
        return $this;
    }
}
