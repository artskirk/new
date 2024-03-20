<?php

namespace Datto\Asset;

/**
 * Metadata about the device responsible for taking the backups of a specific asset.
 * A Siris can be the offsite target for many other Sirises. This class is helpful
 * for identifying the device and reseller from where the asset originated.
 *
 * @author John Roland <jroland@datto.com>
 */
class OriginDevice
{
    /** @var int */
    private $deviceId;

    /** @var int */
    private $resellerId;

    /** @var bool */
    private $isReplicated;

    /** @var bool */
    private $isOrphaned;

    /**
     * Already paired assets may not have origin device info so these fields can be null.
     * (A config repair task replaces nulls with the proper value so you shouldn't see this in practice)
     *
     * @param int|null $deviceId ID of the device responsible for taking backups of the asset
     * @param int|null $resellerId ID of the reseller that owns the device taking the backups
     * @param bool $isReplicated Whether the asset is replicated
     * @param bool $isOrphaned True if the asset does not have a source asset anymore
     */
    public function __construct(
        int $deviceId = null,
        int $resellerId = null,
        bool $isReplicated = false,
        bool $isOrphaned = false
    ) {
        $this->deviceId = $deviceId;
        $this->resellerId = $resellerId;
        $this->isReplicated = $isReplicated;
        $this->isOrphaned = $isOrphaned;
    }

    /**
     * @return int|null
     */
    public function getDeviceId()
    {
        return $this->deviceId;
    }

    /**
     * @param int $deviceId
     */
    public function setDeviceId(int $deviceId): void
    {
        $this->deviceId = $deviceId;
    }

    /**
     * Whether this asset is replicated from another device
     */
    public function isReplicated(): bool
    {
        return $this->isReplicated;
    }

    /**
     * @param bool $isReplicated
     */
    public function setReplicated(bool $isReplicated): void
    {
        $this->isReplicated = $isReplicated;
    }

    /**
     * @return bool
     */
    public function isOrphaned(): bool
    {
        return $this->isOrphaned;
    }

    /**
     * @param bool $isOrphaned
     */
    public function setOrphaned(bool $isOrphaned): void
    {
        $this->isOrphaned = $isOrphaned;
    }

    /**
     * @return int|null
     */
    public function getResellerId()
    {
        return $this->resellerId;
    }

    /**
     * @param int $resellerId
     */
    public function setResellerId(int $resellerId): void
    {
        $this->resellerId = $resellerId;
    }
}
