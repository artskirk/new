<?php

namespace Datto\Utility\Roundtrip;

/**
 * Model class containing NIC information from roundtrip-ng
 *
 * @author Stephen Allan <sallan@datto.com>
 * @codeCoverageIgnore
 */
class NetworkInterface
{
    /** @var string */
    private $name;

    /** @var string */
    private $address;

    /** @var string */
    private $mac;

    /** @var bool */
    private $nicToNic;

    /** @var bool */
    private $carrier;

    /**
     * @param string $name
     * @param string $address
     * @param string $mac
     * @param bool $nicToNic
     * @param bool $carrier
     */
    public function __construct(string $name, string $address, string $mac, bool $nicToNic, bool $carrier)
    {
        $this->name = $name;
        $this->address = $address;
        $this->mac = $mac;
        $this->nicToNic = $nicToNic;
        $this->carrier = $carrier;
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
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string
     */
    public function getMac(): string
    {
        return $this->mac;
    }

    /**
     * @return bool
     */
    public function isNicToNic(): bool
    {
        return $this->nicToNic;
    }

    /**
     * @return bool
     */
    public function isCarrier(): bool
    {
        return $this->carrier;
    }
}
