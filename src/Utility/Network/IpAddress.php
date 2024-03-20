<?php

namespace Datto\Utility\Network;

use JsonSerializable;

/**
 * A simple utility class providing accessors and transformations for an IPv4 address.
 *
 * @author Geoff Amey <gamey@datto.com>
 */
class IpAddress implements JsonSerializable
{
    /** @var int The default subnet prefix (fully-masked) for an IPv4 Address */
    private const DEFAULT_PREFIX = 32;

    /** @var string The default netmask string (fully masked) for an IPv4 Address */
    private const DEFAULT_MASK = '255.255.255.255';

    /** @var int The IP Address in 32-bit integer format */
    private int $addr;

    /** @var int The IP Subnet Mask in 32-bit integer format */
    private int $mask;

    /** @var string The Label for this IpAddress */
    private string $label;

    /**
     * IpAddress constructor (Private).
     *
     * To build an IP address, use the static `fromFoo()` methods.
     */
    private function __construct(int $addr, int $mask, string $label)
    {
        $this->addr = $addr;
        $this->mask = $mask;
        $this->label = $label;
    }

    /**
     * Builds an IP Address or Range from CIDR Notation
     *
     * @param string $cidr CIDR-formatted IP Address (e.g. '10.0.0.14/16' or '192.168.1.14/24')
     * @param string $label Optional label for the address
     *
     * @return IpAddress|null
     */
    public static function fromCidr(string $cidr, string $label = ''): ?IpAddress
    {
        $parts = explode('/', $cidr, 2);
        $addr = self::ipToLong($parts[0]);
        $prefix = $parts[1] ?? self::DEFAULT_PREFIX;
        if (($addr !== false) && self::isValidPrefix($prefix)) {
            return new IpAddress($addr, self::prefix2Mask($prefix), $label);
        }
        return null;
    }

    /**
     * Builds an IP Address or Range from an address and a CIDR subnet prefix
     *
     * @param string $addr The IP Address
     * @param int|null $prefix The CIDR prefix (e.g. 24 for a /24 address)
     * @param string $label Optional label for the address
     *
     * @return IpAddress|null
     */
    public static function fromAddr(string $addr, int $prefix = null, string $label = ''): ?IpAddress
    {
        $prefix = $prefix ?? self::DEFAULT_PREFIX;
        $a = self::ipToLong($addr);
        if (($a !== false) && self::isValidPrefix($prefix)) {
            return new IpAddress($a, self::prefix2Mask($prefix), $label);
        }
        return null;
    }

    /**
     * Builds an IP Address or Range from an address and subnet mask string
     *
     * @param string $addr The address in dotted-decimal notation (e.g. 10.0.0.1 or 192.168.1.1)
     * @param string|null $mask The subnet mask in dotted-decimal notation (e.g. 255.255.255.0)
     * @param string $label Optional label for the address
     *
     * @return IpAddress|null
     * @note Non-contiguous subnet masks (e.g. 128.255.235.0) are incredibly rare, but actually
     * supported in IETF RFC 950, which originally defined subnetting. RFC 1519 which defined
     * CIDR in 1993 officially codified that masks be contiguous. For this reason, this class
     * supports non-contiguous masks, but the CIDR-related prefix functionality is not guaranteed
     * to work properly in this case.
     */
    public static function fromAddrAndMask(string $addr, string $mask = null, string $label = ''): ?IpAddress
    {
        $a = self::ipToLong($addr);
        $m = self::ipToLong($mask ?? self::DEFAULT_MASK);
        if (($a !== false) && ($m !== false)) {
            return new IpAddress($a, $m, $label);
        }
        return null;
    }

    /**
     * Get the label of an IP Address or Range
     *
     * @return string The label for this IP address
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the IP Address
     *
     * @return string The IP Address in dotted-decimal notation (e.g. 172.16.0.1)
     */
    public function getAddr(): string
    {
        return long2ip($this->addr);
    }

    /**
     * Get the subnet mask of an IP Address or Range
     *
     * @return string The subnet mask in dotted-decimal notation (e.g. 255.255.255.0)
     */
    public function getMask(): string
    {
        return long2ip($this->mask);
    }

    /**
     * Get the IP Address and Mask in CIDR notation
     *
     * @return string The address in CIDR notation (e.g. 192.168.10.4/26)
     */
    public function getCidr(): string
    {
        return $this->getAddr() . '/' . $this->getPrefix();
    }

    /**
     * Get the prefix length of an IP Address or Range
     *
     * @return int The IP prefix (mask) bits
     */
    public function getPrefix(): int
    {
        return $this->mask2Prefix($this->mask);
    }

    /**
     * Determine whether or not this IpAddress represents an IP Range (e.g. has a valid
     * prefix or subnet mask)
     *
     * @return bool true if this IpAddress has subnet information
     */
    public function isRange(): bool
    {
        return $this->mask != ip2long(self::DEFAULT_MASK);
    }

    /**
     * Get the base address of an IP Range. Meaningless for an IpAddress created without
     * specifying a prefix or subnet mask.
     *
     * @return string The subnet base address of an IP Range (e.g. 192.168.1.0 for 192.168.1.10/24)
     */
    public function getSubnetBase(): string
    {
        return long2ip($this->addr & $this->mask);
    }

    /**
     * Get the broadcast address of an IP Range. Meaningless for an IpAddress created without
     * specifying a prefix or subnet mask.
     *
     * @return string The broadcast address of an IP Range (e.g. 192.168.1.255 for 192.168.1.10/24)
     */
    public function getBroadcastAddress(): string
    {
        return long2ip($this->addr | ~$this->mask);
    }

    /**
     * Return this object in default string notation
     *
     * @return string The IP Address string representation
     */
    public function __toString(): string
    {
        return sprintf(
            '%s%s%s',
            $this->label ? ($this->label . ': ') : '',
            $this->getAddr(),
            $this->getPrefix() !== self::DEFAULT_PREFIX ? '/' . $this->getPrefix() : ''
        );
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'label' => $this->getLabel(),
            'addr' => $this->getAddr(),
            'mask' => $this->getMask()
        ];
    }

    /**
     * Convert a valid subnet mask to a CIDR prefix
     * @param int $mask
     * @return int
     */
    private static function mask2Prefix(int $mask): int
    {
        // XOR to get the inverse of the mask, then take log base 2 of that. Adding 1 will
        // return the number of off bits in the mask. Subtracting from 32 gets you the count
        // of set bits, which is the CIDR prefix.
        return 32 - (int)log(($mask ^ 0xFFFF_FFFF) + 1, 2);
    }

    /**
     * Convert a CIDR prefix (e.g. 16) to its equivalent netmask (e.g. 0xFFFF0000)
     * @param int $prefix
     * @return int
     */
    private static function prefix2Mask(int $prefix): int
    {
        // Left shift in zeroes for unmasked bits, then cap at 32-bits total length
        return (0xFFFF_FFFF & 0xFFFF_FFFF << (32 - $prefix));
    }

    /**
     * Determine if a CIDR prefix is valid
     * @param int $prefix
     * @return bool
     */
    private static function isValidPrefix(int $prefix): bool
    {
        return $prefix >= 0 && $prefix <= 32;
    }

    /**
     * Convert a dotted-decimal address or mask to an integer type.
     *
     * @param string $addr
     * @return false|int
     */
    private static function ipToLong(string $addr)
    {
        if (preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/', $addr)) {
            // Explode on dots, convert strings to ints using intval, and implode with dots
            // This converts '192.001.000.013' to '192.1.0.13' before passing into ip2long
            return ip2long(implode(".", array_map('intval', explode(".", $addr))));
        }
        return false;
    }
}
