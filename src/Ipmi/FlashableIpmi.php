<?php

namespace Datto\Ipmi;

use Datto\System\Hardware;
use Exception;
use Throwable;

/**
 * Data class that encapsulates information about a flashable IPMI.
 *
 * @author Chad Kosie <ckosie@datto.com>
 */
class FlashableIpmi
{
    const RESOURCE_DIR = '/usr/lib/datto/device/app/Resources/Ipmi/Update';
    const SUPPORTED_MOTHERBOARDS = [
        'MB10-DATTO-O7',        // S3B
        'MD70-DATTO-O7-XX',     // S3E
        'MD71-HB0-00',          // S4E
        'D1541D4U-2T8R',        // S3P
        'D1521D4I2',            // S3P
        'D2143D8UM2',           // Prototype product name for the S4P
        'S4P2143'               // Release product name for the S4P
    ];

    const GIGABYTE_PRESERVE_CONFIG_OFFSET = '0x2C0000';
    const GIGABYTE_ERASE_CONFIG_OFFSET = '0x0';

    const ASROCK_D1_PRESERVE_CONFIG_OFFSET = '0x140000';
    const ASROCK_D1_ERASE_CONFIG_OFFSET = '0x40000';

    const ASROCK_D2_PRESERVE_CONFIG_OFFSET = '0x250000';
    const ASROCK_D2_ERASE_CONFIG_OFFSET = '0x50000';

    /** @var string */
    private $motherboard;

    /** @var string */
    private $homepage;

    /** @var int */
    private $port;

    /** @var string */
    private $firmwarePath;

    /** @var string */
    private $bmcOffsetString;

    /**
     * @param string $motherboard
     * @param string $homepage
     * @param int $port
     * @param string $firmwarePath
     * @param string $bmcOffsetString MUST be a hex string
     */
    private function __construct(
        string $motherboard,
        string $homepage,
        int $port,
        string $firmwarePath,
        string $bmcOffsetString
    ) {
        $this->motherboard = $motherboard;
        $this->homepage = $homepage;
        $this->port = $port;
        $this->firmwarePath = $firmwarePath;
        $this->bmcOffsetString = $bmcOffsetString;
    }

    /**
     * @return string
     */
    public function getMotherboard(): string
    {
        return $this->motherboard;
    }

    /**
     * @return string
     */
    public function getHomepage(): string
    {
        return $this->homepage;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getBmcFirmwarePath(): string
    {
        return $this->firmwarePath;
    }

    /**
     * @return string
     */
    public function getBmcOffsetString(): string
    {
        return $this->bmcOffsetString;
    }

    /**
     * Check if the IPMI of this motherboard is supported.
     *
     * @param Hardware|null $hardware
     * @return bool
     */
    public static function isSupported(Hardware $hardware = null): bool
    {
        try {
            self::create($hardware);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @see docs/IpmiFirmware.md for documentation, source of constants, etc.
     *
     * @param Hardware|null $hardware
     * @return FlashableIpmi
     */
    public static function create(Hardware $hardware = null): FlashableIpmi
    {
        $hardware = $hardware ?? new Hardware();

        $systemName = '';
        $baseboardName = '';

        try {
            $systemName = $hardware->getSystemProductName();
            return self::doCreate($systemName);
        } catch (Throwable $e) {
            // nothing
        }

        try {
            $baseboardName = $hardware->getBaseboardProductName();
            return self::doCreate($baseboardName);
        } catch (Throwable $e) {
            // nothing
        }

        self::throwMotherboardNotSupported(sprintf(
            '"%s" OR "%s"',
            $systemName,
            $baseboardName
        ));
    }

    /**
     * @param string $motherboard
     * @return FlashableIpmi
     */
    private static function doCreate(string $motherboard)
    {
        $motherboard = strtoupper($motherboard);

        switch ($motherboard) {
            case 'MB10-DATTO-O7':
                return new self(
                    'MB10-DATTO-O7',
                    'login.html',
                    443,
                    self::RESOURCE_DIR . '/fw_gigabyte_md70.bin',
                    self::GIGABYTE_PRESERVE_CONFIG_OFFSET
                );

            case 'MD70-DATTO-O7-XX':
                return new self(
                    'MD70-DATTO-O7-XX',
                    'login.html',
                    443,
                    self::RESOURCE_DIR . '/fw_gigabyte_md70.bin',
                    self::GIGABYTE_PRESERVE_CONFIG_OFFSET
                );

            case 'MD71-HB0-00':
                return new self(
                    'MD71-HB0-00',
                    'login.html',
                    443,
                    self::RESOURCE_DIR . '/fw_gigabyte_md71.bin',
                    self::GIGABYTE_PRESERVE_CONFIG_OFFSET
                );

            case 'D1541D4U-2T8R':
                return new self(
                    'D1541D4U-2T8R',
                    'index.html',
                    80,
                    self::RESOURCE_DIR . '/fw_asrock_d1.bin',
                    self::ASROCK_D1_PRESERVE_CONFIG_OFFSET
                );

            case 'D1521D4I2':
                return new self(
                    'D1521D4I2',
                    'index.html',
                    80,
                    self::RESOURCE_DIR . '/fw_asrock_d1.bin',
                    self::ASROCK_D1_PRESERVE_CONFIG_OFFSET
                );

            case 'D2143D8UM2':
                return new self(
                    'D2143D8UM2',
                    'index.html',
                    8000,
                    self::RESOURCE_DIR . '/fw_asrock_d2.bin',
                    self::ASROCK_D2_PRESERVE_CONFIG_OFFSET
                );

            case 'S4P2143':
                return new self(
                    'S4P2143',
                    'index.html',
                    8000,
                    self::RESOURCE_DIR . '/fw_asrock_d2.bin',
                    self::ASROCK_D2_PRESERVE_CONFIG_OFFSET
                );

            default:
                self::throwMotherboardNotSupported($motherboard);
        }
    }

    /**
     * @param string $motherboard
     */
    private static function throwMotherboardNotSupported(string $motherboard)
    {
        throw new Exception("Motherboard is not supported for IPMI flashing: " . $motherboard);
    }
}
