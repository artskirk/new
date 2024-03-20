<?php

namespace Datto\Ipmi;

use Datto\Config\JsonConfigRecord;

/**
 * Config record for storing the most recently flashed IPMI version.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class IpmiVersionRecord extends JsonConfigRecord
{
    /** @var string|null */
    private $bmcFirmwareSha1;

    /** @var int|null */
    private $timestamp;

    /**
     * @param string|null $bmcFirmwareSha1
     * @param int|null $timestamp
     */
    public function __construct(string $bmcFirmwareSha1 = null, int $timestamp = null)
    {
        $this->bmcFirmwareSha1 = $bmcFirmwareSha1;
        $this->timestamp = $timestamp;
    }

    /**
     * @return string name of key file that this config record will be stored to
     */
    public function getKeyName(): string
    {
        return 'ipmiVersion';
    }

    /**
     * @return string|null
     */
    public function getBmcFirmwareSha1()
    {
        return $this->bmcFirmwareSha1;
    }

    /**
     * @return int|null
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * Load the config record instance using json decoded as array
     *
     * @param array $vals
     */
    protected function load(array $vals)
    {
        $this->bmcFirmwareSha1 = $vals['bmcFirmwareSha1'] ?? '';
        $this->timestamp = $vals['timestamp'] ?? -1;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'bmcFirmwareSha1' => $this->bmcFirmwareSha1,
            'timestamp' => $this->timestamp
        ];
    }
}
