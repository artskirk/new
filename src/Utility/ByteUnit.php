<?php

namespace Datto\Utility;

use Eloquent\Enumeration\AbstractEnumeration;
use RuntimeException;

/**
 * Byte IEC Units
 *
 * @author Jason Lodice <JLodice@datto.com
 *
 * @method static ByteUnit BYTE()
 * @method static ByteUnit KIB()
 * @method static ByteUnit MIB()
 * @method static ByteUnit GIB()
 * @method static ByteUnit TIB()
 * @method static ByteUnit PIB()
 */
class ByteUnit extends AbstractEnumeration
{
    const FACTOR = 1024;

    const BYTE = 'B';
    const KIB = 'KiB';
    const MIB = 'MiB';
    const GIB = 'GiB';
    const TIB = 'TiB';
    const PIB = 'PiB';

    /**
     * Get an array of units ordered by increasing magnitude
     *
     * @return array
     */
    public static function getOrderedUnits(): array
    {
        return [ByteUnit::BYTE(), ByteUnit::KIB(), ByteUnit::MIB(), ByteUnit::GIB(), ByteUnit::TIB(), ByteUnit::PIB()];
    }

    /**
     * Convert value from this byte unit to another
     *
     * @param ByteUnit $toUnit the units to convert to
     * @param  float|int $value the value to convert (implictly in units of $this)
     * @return float|int
     */
    public function convertTo(ByteUnit $toUnit, $value)
    {
        $units = ByteUnit::getOrderedUnits();

        if (($indexFrom = array_search($this, $units)) === false) {
            throw new RuntimeException("Invalid ByteUnit '$this'");
        }

        if (($indexTo = array_search($toUnit, $units)) === false) {
            throw new RuntimeException("Invalid ByteUnit '$toUnit'");
        }

        $steps = $indexFrom - $indexTo;
        return $value * pow(static::FACTOR, $steps);
    }

    /**
     * Convert value from this unit to Byte
     *
     * @param $value
     * @return float|int
     */
    public function toByte($value)
    {
        return $this->convertTo(ByteUnit::BYTE(), $value);
    }

    /**
     * Convert value from this unit to KiB
     *
     * @param $value
     * @return float|int
     */
    public function toKiB($value)
    {
        return $this->convertTo(ByteUnit::KIB(), $value);
    }

    /**
     * Convert value from this unit to MiB
     *
     * @param $value
     * @return float|int
     */
    public function toMiB($value)
    {
        return $this->convertTo(ByteUnit::MIB(), $value);
    }

    /**
     * Convert value from this unit to GiB
     *
     * @param $value
     * @return float|int
     */
    public function toGiB($value)
    {
        return $this->convertTo(ByteUnit::GIB(), $value);
    }

    /**
     * Convert value from this unit to TiB
     *
     * @param $value
     * @return float|int
     */
    public function toTiB($value)
    {
        return $this->convertTo(ByteUnit::TIB(), $value);
    }

    /**
     * Convert value from this unit to PiB
     *
     * @param $value
     * @return float|int
     */
    public function toPiB($value)
    {
        return $this->convertTo(ByteUnit::PIB(), $value);
    }
}
