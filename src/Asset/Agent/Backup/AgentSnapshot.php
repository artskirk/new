<?php

namespace Datto\Asset\Agent\Backup;

use Datto\AppKernel;
use Datto\Asset\Agent\IncludedVolumesSettings;
use Datto\Asset\Agent\OperatingSystem;
use Datto\Asset\Agent\Volumes;
use SplFileInfo;

/**
 * Represents information about a snapshot.
 *
 * @author Charles Shapleigh <cshapleigh@datto.com>
 */
class AgentSnapshot
{
    /** @var string */
    protected $keyName;

    /** @var string */
    protected $epoch;

    protected ?Volumes $volumes;
    protected ?Volumes $protectedVolumes;

    /** @var IncludedVolumesSettings */
    protected $desiredVolumes;

    /** @var OperatingSystem */
    protected $operatingSystem;

    /** @var AgentSnapshotRepository */
    protected $repository;

    /** @var DiskDrive[] */
    protected $diskDrives;

    /**
     * @param string $keyName
     * @param int $epoch
     * @param Volumes|null $volumes
     * @param array|null $desiredVolumes
     * @param OperatingSystem|null $operatingSystem
     * @param array|null $diskDrives
     * @param AgentSnapshotRepository|null $repository
     */
    public function __construct(
        string $keyName,
        int $epoch,
        Volumes $volumes = null,
        array $desiredVolumes = null,
        OperatingSystem $operatingSystem = null,
        array $diskDrives = null,
        AgentSnapshotRepository $repository = null
    ) {
        $this->keyName = $keyName;
        $this->epoch = $epoch;
        $this->repository = $repository ?: AppKernel::getBootedInstance()->getContainer()->get(AgentSnapshotRepository::class);
        $this->volumes = $volumes;
        $this->desiredVolumes = $desiredVolumes;
        $this->operatingSystem = $operatingSystem;
        $this->diskDrives = $diskDrives;
        $this->protectedVolumes = null;
    }

    /**
     * Returns the agent snapshot's key name.
     *
     * @return string
     */
    public function getKeyName(): string
    {
        return $this->keyName;
    }

    /**
     * Returns the agent snapshot's epoch.
     *
     * @return int
     */
    public function getEpoch(): int
    {
        return $this->epoch;
    }

    /**
     * Returns the agent snapshot's volumes.
     *
     * @return Volumes
     */
    public function getVolumes(): Volumes
    {
        if ($this->volumes === null) {
            $this->volumes = $this->repository->getVolumes($this->keyName, $this->epoch);
        }

        return $this->volumes;
    }

    /**
     * Returns the agent snapshot's volumes that are to be included.
     *
     * @return IncludedVolumesSettings|null
     */
    public function getDesiredVolumes(): ?IncludedVolumesSettings
    {
        if ($this->desiredVolumes === null) {
            $this->desiredVolumes = $this->repository->getDesiredVolumes($this->keyName, $this->epoch);
        }

        return $this->desiredVolumes;
    }

    public function getProtectedVolumes(): Volumes
    {
        if ($this->protectedVolumes === null) {
            $this->protectedVolumes = $this->repository->getProtectedVolumes($this->keyName, $this->epoch);
        }

        return $this->protectedVolumes;
    }

    /**
     * Returns the full disk images that were included
     *
     * @return DiskDrive[]|null
     */
    public function getDiskDrives()
    {
        if ($this->diskDrives === null) {
            $this->diskDrives = $this->repository->getDiskDrives($this->keyName, $this->epoch);
        }

        return $this->diskDrives;
    }

    /**
     * Returns the agent snapshot's operating system.
     *
     * @return OperatingSystem|null
     */
    public function getOperatingSystem()
    {
        if ($this->operatingSystem === null) {
            $this->operatingSystem = $this->repository->getOperatingSystem($this->keyName, $this->epoch);
        }

        return $this->operatingSystem;
    }

    /**
     * Get the raw contents of the keyfile or false if it does not exist.
     *
     * @param string $keyFile
     * @return string|false
     */
    public function getKey(string $keyFile)
    {
        return $this->repository->getKeyContents($this->keyName, $this->epoch, $keyFile);
    }

    /**
     * Get info about the key file.
     *
     * @param string $keyFile
     * @return SplFileInfo
     */
    public function getKeyInfo(string $keyFile): SplFileInfo
    {
        return $this->repository->getKeyInfo($this->keyName, $this->epoch, $keyFile);
    }
}
