<?php

namespace Datto\Utility\Roundtrip;

/**
 * Model class containing NAS target information from roundtrip-ng
 *
 * @author Stephen Allan <sallan@datto.com>
 * @codeCoverageIgnore
 */
class NasTarget
{
    /** @var string */
    private $hostname;

    /** @var string */
    private $address;

    /** @var string */
    private $name;

    /** @var string */
    private $protocolVersion;

    /** @var int */
    private $size;

    /**
     * @param string $hostname
     * @param string $address
     * @param string $name
     * @param string $protocolVersion
     * @param int $size
     */
    public function __construct(
        string $hostname,
        string $address,
        string $name,
        string $protocolVersion,
        int $size
    ) {
        $this->hostname = $hostname;
        $this->address = $address;
        $this->name = $name;
        $this->protocolVersion = $protocolVersion;
        $this->size = $size;
    }

    /**
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @return int
     */
    public function getSize(): int
    {
        return $this->size;
    }
}
