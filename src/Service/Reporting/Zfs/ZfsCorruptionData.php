<?php

namespace Datto\Service\Reporting\Zfs;

use Datto\Config\JsonConfigRecord;

/**
 * This class contains data related to the output of ZFS when checking for zpool corruption
 *
 * @author Christopher Bitler <cbitler@datto.com>
 * @author Jeffrey Knapp <jknapp@datto.com>
 */
class ZfsCorruptionData extends JsonConfigRecord
{
    /** @var int */
    private $zpoolErrorCount;

    /** @var string */
    private $noWriteThrottle;

    /** @var string */
    private $kzfsVersion;

    /**
     * @param int $zpoolErrorCount
     * @param string $writeThrottleConfig
     * @param string $kzfsVersion
     */
    public function __construct(
        int $zpoolErrorCount = 0,
        string $writeThrottleConfig = '',
        string $kzfsVersion = ''
    ) {
        $this->zpoolErrorCount = $zpoolErrorCount;
        $this->noWriteThrottle = $writeThrottleConfig;
        $this->kzfsVersion = $kzfsVersion;
    }

    /**
     * @inheritDoc
     */
    public function getKeyName(): string
    {
        return 'zpoolCorruptionEventsCache';
    }

    /**
     * Get the number of zpool errors
     *
     * @return int Number of zpool errors
     */
    public function getZpoolErrorCount(): int
    {
        return $this->zpoolErrorCount;
    }

    /**
     * Get the value for zfs no_write_throttle
     *
     * @return string ZFS no_write_throttle parameter value
     */
    public function getNoWriteThrottle(): string
    {
        return $this->noWriteThrottle;
    }

    /**
     * Get the KZFS version
     *
     * @return string The KZFS version
     */
    public function getKzfsVersion(): string
    {
        return $this->kzfsVersion;
    }

    /**
     * Check to see if other ZfsCorruptionData matches this one's
     *
     * @return bool True if the same, false otherwise
     */
    public function isSame(ZfsCorruptionData $other): bool
    {
        $isSame = ($this->getZpoolErrorCount() === $other->getZpoolErrorCount() &&
            $this->getKzfsVersion() === $other->getKzfsVersion() &&
            $this->getNoWriteThrottle() === $other->getNoWriteThrottle()
        );
        return $isSame;
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'zpoolErrorCount' => $this->getZpoolErrorCount(),
            'writeThrottle' => $this->getNoWriteThrottle(),
            'kzfsVersion' => $this->getKzfsVersion()
        ];
    }

    /**
     * @inheritDoc
     */
    protected function load(array $vals)
    {
        $this->zpoolErrorCount = $vals['zpoolErrorCount'] ?? 0;
        $this->noWriteThrottle = $vals['writeThrottle'] ?? '';
        $this->kzfsVersion = $vals['kzfsVersion'] ?? '';
    }
}
