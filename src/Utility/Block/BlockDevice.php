<?php

namespace Datto\Utility\Block;

/**
 * Represents a block devices as reported by lsblk.
 *
 * @author Peter Geer <pgeer@datto.com>
 */
class BlockDevice
{
    /** @var string */
    private $path;

    /** @var string */
    private $type;

    /** @var string */
    private $transport;

    /** @var bool */
    private $rotational;

    /** @var int|null */
    private $scsiHostNumber;

    /** @var int|null */
    private $lunId;

    /**
     * @param string $path
     * @param string $type
     * @param string $transport
     * @param bool $rotational
     * @param int|null $scsiHostNumber
     * @param int|null $lunId
     */
    public function __construct(
        string $path,
        string $type,
        string $transport,
        bool $rotational,
        int $scsiHostNumber = null,
        int $lunId = null
    ) {
        $this->path = $path;
        $this->type = $type;
        $this->transport = $transport;
        $this->rotational = $rotational;
        $this->scsiHostNumber = $scsiHostNumber;
        $this->lunId = $lunId;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return basename($this->path);
    }

    /**
     * @return bool
     */
    public function isDisk(): bool
    {
        return $this->type === "disk";
    }

    /**
     * @return bool
     */
    public function isRotational(): bool
    {
        return $this->rotational;
    }

    /**
     * @return bool
     */
    public function isUsb(): bool
    {
        return $this->transport === "usb";
    }

    /**
     * Returns true if the device has a physical transport type specified, e.g. "usb", "sata", "sas", or "nvme".
     * Returns false for zdX and loopX devices.
     *
     * @return bool
     */
    public function hasTransport(): bool
    {
        return !empty($this->transport);
    }

    /**
     * @return int|null
     */
    public function getScsiHostNumber()
    {
        return $this->scsiHostNumber;
    }

    /**
     * @return int|null
     */
    public function getLunId()
    {
        return $this->lunId;
    }
}
